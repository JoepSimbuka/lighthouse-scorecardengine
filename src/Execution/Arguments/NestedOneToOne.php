<?php

namespace Nuwave\Lighthouse\Execution\Arguments;

use Illuminate\Support\Collection;
use Nuwave\Lighthouse\Support\Contracts\ArgResolver;

class NestedOneToOne implements ArgResolver
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
		/** @var \Illuminate\Database\Eloquent\Relations\HasOne|\Illuminate\Database\Eloquent\Relations\MorphOne $relation */
		$relation = $parent->{$this->relationName}();

		$reflection = new \ReflectionClass($relation->getRelated());
		$availability = $reflection->getStaticPropertyValue('AVAILABILITY_IN_GRAPHQL');

		$current_related = $parent->{$this->relationName};

		if ($args->has('id')) {
			$data = $args->toArray();
			if ($data['id'] !== $current_related->id) {
				// If allowed delete current association
				if (is_array($availability) && $availability['delete'] !== false) {
					$current_related->cascadeDelete();
				}
			}
			if (is_array($availability) && $availability['update'] !== false) {
				$updateModel = new ResolveNested(new UpdateModel(new SaveModel($relation)));
				$updateModel($relation->make(), $args);
			}
		} elseif (is_array($availability) && $availability['create'] !== false) {
			$saveModel = new ResolveNested(new SaveModel($relation));
			$saveModel($relation->make(), $args);
		}
	}
}
