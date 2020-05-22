<?php

namespace Nuwave\Lighthouse\Execution\Arguments;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nuwave\Lighthouse\Support\Contracts\ArgResolver;

class NestedBelongsTo implements ArgResolver
{
	/**
	 * @var \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	protected $relation;

	public function __construct(BelongsTo $relation)
	{
		$this->relation = $relation;
	}

	/**
	 * @param  \Illuminate\Database\Eloquent\Model  $parent
	 * @param  \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet  $args
	 */
	public function __invoke($parent, $args): void
	{
		$reflection = new \ReflectionClass($this->relation->getRelated());
		$availability = $reflection->getStaticPropertyValue('AVAILABILITY_IN_GRAPHQL');

		if ($args->has('id')) {
			if (is_array($availability) && $availability['update'] === true) {
				$updateModel = new ResolveNested(new UpdateModel(new SaveModel($this->relation)));

				$related = $updateModel(
					$this->relation->make(),
					$args
				);
				$this->relation->associate($related);
			} else {
				$this->relation->associate($args->arguments['id']->value);
			}
		} else {
			$this->relation->dissociate();
			// If allowed delete current association
			if (is_array($availability) && $availability['delete'] === true) {
				$this->relation->delete();
			}

			if (is_array($availability) && $availability['create'] === true) {
				$saveModel = new ResolveNested(new SaveModel($this->relation));

				$related = $saveModel(
					$this->relation->make(),
					$args
				);
				$this->relation->associate($related);
			}
		}
	}
}
