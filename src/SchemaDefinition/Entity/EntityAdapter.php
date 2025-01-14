<?php declare(strict_types = 1);

namespace Apitte\OpenApi\SchemaDefinition\Entity;

use Apitte\Core\Exception\Logical\InvalidArgumentException;
use Apitte\Core\Exception\Logical\InvalidStateException;
use DateTimeInterface;
use Nette\Utils\Reflection;
use Nette\Utils\Strings;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionFunctionAbstract;
use ReflectionProperty;
use Reflector;

class EntityAdapter implements IEntityAdapter
{

	/**
	 * @return mixed[]
	 */
	public function getMetadata(string $type, ?string $description = null): array
	{
		// Ignore brackets (not supported by schema)
		$type = str_replace(['(', ')'], '', $type);

		// Normalize null type
		$type = str_replace('?', 'null|', $type);

		$usesUnionType = Strings::contains($type, '|');
		$usesIntersectionType = Strings::contains($type, '&');

		$descriptionData = $description !== null ? ['description' => $description] : [];

		// Get schema for all possible types
		if ($usesUnionType || $usesIntersectionType) {
			$types = preg_split('#([&|])#', $type, -1, PREG_SPLIT_NO_EMPTY);

			// Filter out duplicate definitions
			$types = array_map(function (string $type): string {
				return $this->normalizeType($type);
			}, $types);
			$types = array_unique($types);

			$metadata = [] + $descriptionData;
			$nullKey = array_search('null', $types, true);

			// Remove null from other types
			if ($nullKey !== false) {
				unset($types[$nullKey]);
				$metadata['nullable'] = true;
			}

			// Types contain single, nullable value
			if (count($types) === 1) {
				return array_merge($metadata, $this->getMetadata(current($types)));
			}

			$resolvedTypes = [];
			foreach ($types as $subType) {
				$resolvedTypes[] = $this->getMetadata($subType);
			}

			if ($usesUnionType && $usesIntersectionType) {
				$schemaCombination = 'anyOf';
			} elseif ($usesUnionType) {
				$schemaCombination = 'oneOf';
			} else {
				$schemaCombination = 'allOf';
			}

			$metadata[$schemaCombination] = $resolvedTypes;

			return $metadata;
		}

		// Get schema for array
		if (Strings::endsWith($type, '[]')) {
			$subType = Strings::replace($type, '#\\[\\]#', '');

			return [
				'type' => 'array',
				'items' => $this->getMetadata($subType),
			] + $descriptionData;
		}

		// Get schema for class
		if (class_exists($type)) {
			// String is converted to DateTimeInterface internally in core
			if (is_subclass_of($type, DateTimeInterface::class)) {
				return [
					'type' => 'string',
					'format' => 'date-time',
				] + $descriptionData;
			}

			return [
				'type' => 'object',
				'properties' => $this->getProperties($type),
				'required' => $this->getRequiredPropertyNames($type),
			] + $descriptionData;
		}

		$lower = strtolower($type);

		// For php and phpstan is mixed absolutely anything, including null -> write in schema property accepts anything
		if ($lower === 'mixed') {
			return [
				'nullable' => true,
			] + $descriptionData;
		}

		if ($lower === 'object' || interface_exists($type)) {
			return [
				'type' => 'object',
			] + $descriptionData;
		}

		// Get schema for scalar type
		return [
			'type' => $this->phpScalarTypeToOpenApiType($type),
		] + $descriptionData;
	}

	/**
	 * @return mixed[]
	 */
	protected function getProperties(string $type): array
	{
		if (!class_exists($type)) {
			return [];
		}

		$ref = new ReflectionClass($type);
		$properties = $ref->getProperties(ReflectionProperty::IS_PUBLIC);
		$data = [];

		foreach ($properties as $property) {
			$propertyType = $this->getPropertyType($property) ?? 'string';
			$description = $this->getPropertyDescription($property);

			// Self-reference not supported
			if ($propertyType === $type) {
				$propertyType = 'object';
			}

			$data[$property->getName()] = $this->getMetadata($propertyType, $description);
		}

		return $data;
	}

	/**
	 * @return array<string>
	 */
	protected function getRequiredPropertyNames(string $type): array
	{
		if (!class_exists($type)) {
			return [];
		}

		$ref = new ReflectionClass($type);
		$properties = $ref->getProperties(ReflectionProperty::IS_PUBLIC);
		$data = [];

		foreach ($properties as $property) {
			if ($this->getPropertyRequiredFlag($property)) {
				$data[] = $property->getName();
			}
		}

		return $data;
	}

	private function getPropertyDescription(ReflectionProperty $property): ?string
	{
		return $this->parseAnnotationDescription($property);
	}

	private function getPropertyType(ReflectionProperty $property): ?string
	{
		//TODO - fix typed properties support
		//if (PHP_VERSION_ID >= 70400 && ($type = Reflection::getPropertyType($property) !== null)) {
		//	return ($property->getType()->allowsNull() ? '?' : '') . $type;
		//}

		$annotation = $this->parseAnnotation($property, 'var');

		if ($annotation === null) {
			return null;
		}

		if (($type = preg_replace('#\s.*#', '', $annotation)) !== null) {
			$class = Reflection::getPropertyDeclaringClass($property);

			return preg_replace_callback('#[\w\\\\]+#', function ($m) use ($class): string {
				static $phpdocKnownTypes = [
					// phpcs:disable
					'bool', 'boolean', 'false', 'true',
					'int', 'integer',
					'float', 'double',
					'string', 'numeric', 'mixed', 'object',
					// phpcs:enable
				];

				$lower = $m[0];

				if (in_array($lower, $phpdocKnownTypes, true)) {
					return $this->normalizeType($lower);
				}

				// Self-reference not supported
				if (in_array($lower, ['static', 'self'], true)) {
					return 'object';
				}

				return Reflection::expandClassName($m[0], $class);
			}, $type);
		}

		return null;
	}

	private function getPropertyRequiredFlag(ReflectionProperty $property): bool
	{
		$annotation = $this->parseRequiredAnnotation($property);

		return filter_var($annotation, FILTER_VALIDATE_BOOLEAN);
	}

	/**
	 * @param ReflectionClass|ReflectionClassConstant|ReflectionProperty|ReflectionFunctionAbstract $ref
	 */
	private function parseAnnotation(Reflector $ref, string $name): ?string
	{
		if (!Reflection::areCommentsAvailable()) {
			throw new InvalidStateException('You have to enable phpDoc comments in opcode cache.');
		}

		$re = '#[\s*]@' . preg_quote($name, '#') . '(?=\s|$)(?:[ \t]+([^@\s]\S*))?#';
		if ($ref->getDocComment() && preg_match($re, trim($ref->getDocComment(), '/*'), $m)) {
			return $m[1] ?? null;
		}

		return null;
	}

	/**
	 * @param ReflectionClass|ReflectionClassConstant|ReflectionProperty|ReflectionFunctionAbstract $ref
	 */
	private function parseRequiredAnnotation(Reflector $ref): ?string
	{
		if (!Reflection::areCommentsAvailable()) {
			throw new InvalidStateException('You have to enable phpDoc comments in opcode cache.');
		}

		$re = '#[\s*]@required\s*\(\s*(\S*)\s*\)#';
		if ($ref->getDocComment() && preg_match($re, trim($ref->getDocComment(), '/*'), $m)) {
			return $m[1] ?? null;
		}

		return null;
	}

	/**
	 * @param ReflectionClass|ReflectionClassConstant|ReflectionProperty|ReflectionFunctionAbstract $ref
	 */
	private function parseAnnotationDescription(Reflector $ref): ?string
	{
		if (!Reflection::areCommentsAvailable()) {
			throw new InvalidStateException('You have to enable phpDoc comments in opcode cache.');
		}

		$re = '#[\s*]@description[ \t]+(.+)#';
		if ($ref->getDocComment() && preg_match($re, trim($ref->getDocComment(), '/*'), $m)) {
			return $m[1] ?? null;
		}

		return null;
	}

	/**
	 * Converts scalar types (including phpdoc types and reserved words) to open api types
	 */
	protected function phpScalarTypeToOpenApiType(string $type): string
	{
		// Mixed and null not included, they are handled their own special way
		static $map = [
			'int' => 'integer',
			'float' => 'number',
			'bool' => 'boolean',
			'string' => 'string',
		];

		$type = $this->normalizeType($type);
		$lower = strtolower($type);

		if (!array_key_exists($lower, $map)) {
			throw new InvalidArgumentException(sprintf('Unsupported or unconvertible variable type \'%s\'', $type));
		}

		return $map[$lower];
	}

	protected function normalizeType(string $type): string
	{
		static $map = [
			'integer' => 'int',
			'double' => 'float',
			'numeric' => 'float',
			'boolean' => 'bool',
			'false' => 'bool',
			'true' => 'bool',
		];

		return $map[strtolower($type)] ?? $type;
	}

}
