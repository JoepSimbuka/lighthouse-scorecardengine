<?php

namespace Nuwave\Lighthouse\Schema\Directives;

use App\Models\ChildOfGraphQLUnion;
use App\Models\ParentOfGraphQLUnion;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Execution\Arguments\Argument;
use Nuwave\Lighthouse\Execution\Arguments\ArgumentSet;
use Nuwave\Lighthouse\Execution\Arguments\ArgumentSetFactory;
use Nuwave\Lighthouse\Execution\Arguments\ResolveNested;
use Nuwave\Lighthouse\Schema\AST\ASTBuilder;
use Nuwave\Lighthouse\Schema\AST\ASTHelper;
use Nuwave\Lighthouse\Schema\Factories\ArgumentFactory;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\ArgResolver;
use Nuwave\Lighthouse\Support\Contracts\DefinedDirective;
use Nuwave\Lighthouse\Support\Contracts\FieldResolver;
use Nuwave\Lighthouse\Support\Contracts\GlobalId;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Nuwave\Lighthouse\Support\Utils;

abstract class MutationExecutorDirective extends BaseDirective implements FieldResolver, DefinedDirective, ArgResolver
{
	/**
	 * The database manager.
	 *
	 * @var \Illuminate\Database\DatabaseManager
	 */
	protected $databaseManager;

	/**
	 * The GlobalId resolver.
	 *
	 * @var \Nuwave\Lighthouse\Support\Contracts\GlobalId
	 */
	protected $globalId;

	/**
	 * @var ArgumentSetFactory
	 */
	protected $argumentSetFactory;

	public function __construct(DatabaseManager $databaseManager, GlobalId $globalId, ArgumentSetFactory $argumentSetFactory)
	{
		$this->databaseManager = $databaseManager;
		$this->globalId = $globalId;
		$this->argumentSetFactory = $argumentSetFactory;
	}

	/**
	 * Resolve the field directive.
	 */
	public function resolveField(FieldValue $fieldValue): FieldValue
	{
		return $fieldValue->setResolver(
			function ($root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Model {
				$modelClass = $this->getModelClass();
				/** @var \Illuminate\Database\Eloquent\Model $model */
				$model = new $modelClass;

				$args = $this->transformArguments($model, $args);
				$argumentSet = new ArgumentSet();
				foreach($args as $key => $value) {
					$argumentSet->arguments[$key] = $this->createArgument($value);
				}

				$executeMutation = function () use ($model, $argumentSet): Model {
					$created_model = $this
						->executeMutation(
							$model,
							$argumentSet
						)
						->refresh();
					$this->preCommitDatabaseTransactionChecks($created_model);
					return $created_model;
				};

				return config('lighthouse.transactional_mutations', true)
					? $this
						->databaseManager
						->connection(
							$model->getConnectionName()
						)
						->transaction($executeMutation)
					: $executeMutation();
			}
		);
	}

	private function createArgument($value) {
		$argument = new Argument();
		if (is_array($value)) {
			$argumentSet = new ArgumentSet();
			foreach($value as $key => $arg) {
				$argumentSet->arguments[$key] = $this->createArgument($arg);
			}
			$argument->value = $argumentSet;
		} else {
			$argument = new Argument();
			$argument->value = $value;
		}
		return $argument;
	}

	/**
	 * @param  \Illuminate\Database\Eloquent\Model  $parent
	 * @param  \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet|\Nuwave\Lighthouse\Execution\Arguments\ArgumentSet[]  $args
	 * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Model[]
	 */
	public function __invoke($parent, $args)
	{
		$relationName = $this->directiveArgValue('relation')
			// Use the name of the argument if no explicit relation name is given
			?? $this->nodeName();

		/** @var \Illuminate\Database\Eloquent\Relations\Relation $relation */
		$relation = $parent->{$relationName}();
		$related = $relation->make();

		return $this->executeMutation($related, $args, $relation);
	}

	/**
	 * @param  \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet|\Nuwave\Lighthouse\Execution\Arguments\ArgumentSet[]  $args
	 * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Model[]
	 */
	protected function executeMutation(Model $model, $args, ?Relation $parentRelation = null)
	{
		$update = new ResolveNested($this->makeExecutionFunction($parentRelation));

		return Utils::applyEach(
			static function (ArgumentSet $argumentSet) use ($update, $model) {
				return $update($model->newInstance(), $argumentSet);
			},
			$args
		);
	}

	/**
	 * Prepare the execution function for a mutation on a model.
	 */
	abstract protected function makeExecutionFunction(?Relation $parentRelation = null): callable;


	/**
	 * @param \Illuminate\Database\Eloquent\Model $model
	 * @param array $args
	 * @return array
	 * @throws \ReflectionException
	 */
	private function transformArguments(\Illuminate\Database\Eloquent\Model $model, $args) : array {
		$args = $this->transformToCamelCase($args);
		$args = $this->checkArgumentsForEnumeration($model, $args);
		$args = $this->checkForUnions($args);

		return $args;
	}


	/**
	 * @param \Illuminate\Database\Eloquent\Model $model
	 * @param array $args
	 * @return array
	 * @throws \ReflectionException
	 */
	private function checkArgumentsForEnumeration(\Illuminate\Database\Eloquent\Model $model, array $args) : array {
		$reflection_class = new \ReflectionClass($model);
		foreach ($args as $field => $value) {
			if ($reflection_class->hasMethod($field)) {
				$method = $reflection_class->getMethod($field);
				$return_type = $method->getReturnType();
				if ($return_type !== null) {
					if (is_numeric($value) && BelongsTo::class === $return_type->getName()) {
						$args[$field] = ['id' => $value];
					} elseif (is_array($value) && BelongsToMany::class === $return_type->getName() && (is_numeric(reset($value)) || empty($value))) {
						$args[$field] = [];
						foreach ($value as $key => $id) {
							$args[$field][] = $id;
						}
					}
				}
			}
		}
		return $args;
	}


	private function checkForUnions($args) {
		if (array_key_exists('typename', $args)) {
			$name = $args['typename'];
			unset($args['typename']);

			/** @var ChildOfGraphQLUnion|\App\Models\Model $child */
			$child = \App\Models\Model::getModelFromClassName($name);
			$casts = $child->getCasts();
			try {
				$relations = $child->getAvailableRelations()->toArray();
			} catch (\ReflectionException $e) {
				$relations = [];
			}
			if (array_key_exists('id', $args)) {
				// The given id is of the parent (g.e. ScorecardRule) and we only know the name of the child (g.e. ScorecardAttributeRule)
				// So we first need fetch the model of the parent before we can fetch the id of the child.
				$parent = $child->getParentModel();
				/** @var ParentOfGraphQLUnion|Model $parent */
				$parent = $parent::query()->findOrFail($args['id']);
				$child = $parent->getChild($name);
				if ($child) {
					$child_id = $child->id;
				}
			}

			$args[$name] = [];
			// move properties of child to new nested record
			foreach ($args as $property => $value) {
				if ($property !== 'id'
					&& $property !== $name
					&& (array_key_exists(Str::snake($property), $casts) || array_key_exists($property, $relations))) {
					$args[$name][$property] = $value;
					unset($args[$property]);
				}
			}

			// add id of child
			if (isset($child_id)) {
				$args[$name]['id'] = $child_id;
			}
		}
		// recursively walk though arguments
		foreach ($args as $key => $value) {
			if (is_array($value)) {
				$args[$key] = $this->checkForUnions($value);
			}
		}

		return $args;
	}


	private function transformToCamelCase($args) {
		foreach ($args as $key => $value) {
			if (is_array($value)) {
				$args[$key] = $this->transformToCamelCase($value);
			}
			if (!is_numeric($key)) {
				$camel = Str::camel($key);
				if ($camel !== $key) {
					$args[$camel] = $args[$key];
					unset($args[$key]);
				}
			}
		}

		return $args;
	}

	/**
	 * @param \Illuminate\Database\Eloquent\Model $model
	 */
	private function preCommitDatabaseTransactionChecks($model): void {
		if (method_exists($model, 'preCommitDatabaseTransactionChecks')) {
			$model->preCommitDatabaseTransactionChecks();
		}
	}
}
