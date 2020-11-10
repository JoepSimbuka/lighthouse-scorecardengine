<?php

namespace Nuwave\Lighthouse\Execution\Arguments;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Nuwave\Lighthouse\Support\Contracts\ArgResolver;

class NestedManyToMany implements ArgResolver
{
	/**
	 * @var string
	 */
	private $relationName;

	public function __construct(string $relationName)
	{
		$this->relationName = $relationName;
	}

	/**
	 * @param  \Illuminate\Database\Eloquent\Model  $parent
	 * @param  \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet  $args
	 * @return void
	 */
	public function __invoke($parent, $args)
	{
		/** @var \Illuminate\Database\Eloquent\Relations\BelongsToMany|\Illuminate\Database\Eloquent\Relations\MorphToMany $relation */
		$relation = $parent->{$this->relationName}();

		$reflection = new \ReflectionClass($relation->getRelated());
		$availability = $reflection->getStaticPropertyValue('AVAILABILITY_IN_GRAPHQL');

		if (!is_array($availability) || $availability['create'] === false) {
			$relation->sync($this->generateRelationArray($args));
		} else {
			$current_related = $parent->{$this->relationName};
			$related_to_keep = [];

			foreach ($args->arguments as $nested_operation) {
				$array = $nested_operation->toPlain();
				if (!isset($array['id'])) {
					$saveModel = new ResolveNested(new SaveModel($relation));
					$saveModel($relation->make(), $nested_operation->value);
				} else {
					$related_to_keep[] = $array['id'];
					if (is_array($availability) && $availability['update'] !== false) {
						$updateModel = new ResolveNested(new UpdateModel(new SaveModel($relation)));
						$updateModel($relation->make(), $nested_operation->value);
					} else {
						$relation->attach($array['id']);
					}
				}
			}

			foreach ($current_related as $related) {
				if (!in_array($related->id, $related_to_keep, false)) {
					$relation->detach($related->id);
					if (is_array($availability) && $availability['delete'] !== false) {
						$related->cascadeDelete();
					}
				}
			}
		}
	}

	/**
	 * Generate an array for passing into sync, syncWithoutDetaching or connect method.
	 *
	 * Those functions natively have the capability of passing additional
	 * data to store in the pivot table. That array expects passing the id's
	 * as keys, so we transform the passed arguments to match that.
	 *
	 * @param  \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet $args
	 * @return mixed[]
	 */
	private function generateRelationArray(ArgumentSet $args): array
	{
		$values = $args->toArray();

		if (empty($values)) {
			return [];
		}

		// Since GraphQL inputs are monomorphic, we can just look at the first
		// given value and can deduce the value of all given args.
		$exemplaryValue = $values[0];

		// We assume that the values contain pivot information
		if (is_array($exemplaryValue)) {
			$relationArray = [];
			foreach ($values as $value) {
				$id = Arr::pull($value, 'id');
				$relationArray[$id] = $value;
			}

			return $relationArray;
		}

		// The default case is simply a flat array of IDs which we don't have to transform
		return $values;
	}
}
