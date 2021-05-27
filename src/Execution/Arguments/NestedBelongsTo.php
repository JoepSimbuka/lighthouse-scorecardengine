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
			if (is_array($availability) && $availability['update'] !== false) {
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
			// If allowed delete current association
			if (is_array($availability) && $availability['delete'] !== false) {
				if($model = @$parent->{$this->relation->getRelationName()}){
					$model->cascadeDelete();
				}
			}
			$this->relation->dissociate();

			if (!empty($args->arguments) && is_array($availability) && $availability['create'] !== false) {
				$saveModel = new ResolveNested(new SaveModel($this->relation));

				$related = $saveModel(
					$this->relation->make(),
					$args
				);
				$this->relation->associate($related);
			}
		}
	}

	public static function disconnectOrDelete(BelongsTo $relation, ArgumentSet $args): void
	{
		// We proceed with disconnecting/deleting only if the given $values is truthy.
		// There is no other information to be passed when issuing those operations,
		// but GraphQL forces us to pass some value. It would be unintuitive for
		// the end user if the given value had no effect on the execution.
		if (
			$args->has('disconnect')
			&& $args->arguments['disconnect']->value
		) {
			$relation->dissociate();
		}

		if (
			$args->has('delete')
			&& $args->arguments['delete']->value
		) {
			$relation->dissociate();
			$relation->delete();
		}
	}
}
