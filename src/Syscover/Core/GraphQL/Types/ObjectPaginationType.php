<?php namespace Syscover\Core\GraphQL\Types;

use Folklore\GraphQL\Support\Type as GraphQLType;
use GraphQL;
use GraphQL\Type\Definition\Type;
use Illuminate\Support\Facades\DB;
use Syscover\Core\Services\SQLService;
use Syscover\Core\GraphQL\ScalarTypes\ObjectType;

class ObjectPaginationType extends GraphQLType
{
    protected $attributes = [
        'name'          => 'ObjectPaginationType',
        'description'   => 'Pagination for database objects'
    ];
    private $filtered;

    public function fields()
    {
        return [
            'total' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The total records'
            ],
            'objects' => [
                'type' => Type::listOf(app(ObjectType::class)),
                'description' => 'List of objects filtered',
                'args' => [
                    'sql' => [
                        'type' => Type::listOf(GraphQL::type('CoreSQLQueryInput')),
                        'description' => 'Field to add SQL operations'
                    ],
                    'filters' => [
                        'type' => Type::listOf(GraphQL::type('CoreSQLQueryInput')),
                        'description' => 'Field to add SQL operations, this argument is used to filter all results, for example in multi-language records'
                    ]
                ]
            ],
            'filtered' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'N records filtered'
            ]
        ];
    }

    public function resolveTotalField($object)
    {
        $total = SQLService::countPaginateTotalRecords($object->query);

        // to count elements, if sql has a groupBy statement, count function always return 1
        // check if total is equal to 1, execute FOUND_ROWS() to guarantee the real result
        // https://github.com/laravel/framework/issues/22883
        // https://github.com/laravel/framework/issues/4306
        return $total === 1 ? DB::select(DB::raw("SELECT FOUND_ROWS() AS 'total'"))[0]->total : $total;
    }

    /**
     * With $object parameter access to parent PaginationQuery resolve, where execute the builder of class
     * for example, ActionsPaginationQuery execute the resolve method and return query that is used in resolveObjectsField method
     */
    public function resolveObjectsField($object, $args)
    {
        // save eager loads to load after execute FOUND_ROWS() MySql Function
        // FOUND_ROWS function get total number rows of last query, if model has eagerLoads, after execute the query model,
        // will execute eagerLoads losing the reference os last query to execute FOUND_ROWS() MySql Function
        $eagerLoads = $object->query->getEagerLoads();
        $query      = $object->query->setEagerLoads([]);

        // get query filtered by sql statement and filterd by filters statement
        $query = SQLService::getQueryFiltered($query, $args['sql'] ?? null, $args['filters'] ?? null);

        // get query ordered and limited
        $query = SQLService::getQueryOrderedAndLimited($query, $args['sql'] ?? null);

        // get objects filtered
        $objects = $query->get();

        // execute FOUND_ROWS() MySql Function and save filtered value, to be returned in resolveFilteredField() function
        // this function is executed after resolveObjectsField according to the position of fields marked in the GraphQL query
        $this->filtered = DB::select(DB::raw("SELECT FOUND_ROWS() AS 'filtered'"))[0]->filtered;

        // load eager loads
        $objects->load($eagerLoads);

        return $objects;
    }

    public function resolveFilteredField()
    {
        return $this->filtered;
    }
}