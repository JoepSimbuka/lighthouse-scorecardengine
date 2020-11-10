<?php

namespace Nuwave\Lighthouse\Execution\Arguments;

use App\Exceptions\CustomException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Nuwave\Lighthouse\Support\Contracts\ArgResolver;

class NestedOneToMany implements ArgResolver
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
		/** @var \Illuminate\Database\Eloquent\Relations\HasMany|\Illuminate\Database\Eloquent\Relations\MorphMany $relation */
		$relation = $parent->{$this->relationName}();

		$reflection = new \ReflectionClass($relation->getRelated());
		$availability = $reflection->getStaticPropertyValue('AVAILABILITY_IN_GRAPHQL');

		$current_related = $parent->{$this->relationName};
		$related_to_keep = [];

		foreach ($args->arguments as $nested_operation) {
			$array = $nested_operation->toPlain();
			if (is_array($availability) && isset($array['id']) && $availability['update'] !== false) {
				$related_to_keep[] = $array['id'];
				$updateModel = new ResolveNested(new UpdateModel(new SaveModel($relation)));
				$updateModel($relation->make(), $nested_operation->value);
			} elseif (is_array($availability) && $availability['create'] !== false) {
				$saveModel = new ResolveNested(new SaveModel($relation));
				$saveModel($relation->make(), $nested_operation->value);
			} else {
				throw new \RuntimeException('Not allowed to create or update' . ' ' . $this->relationName);
			}
		}

		foreach ($current_related as $related) {
			if (!in_array($related->id, $related_to_keep, false)) {
				if (is_array($availability) && $availability['delete'] !== false) {
					$related->cascadeDelete();
				}
			}
		}
	}
}
