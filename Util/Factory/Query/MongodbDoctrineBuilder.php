<?php

namespace Ali\DatatableBundle\Util\Factory\Query;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ORM\Query;
use Doctrine\ODM\MongoDB\Query\Builder;
use MongoDB\BSON\Regex;
use Symfony\Component\HttpFoundation\RequestStack;


class MongodbDoctrineBuilder implements QueryInterface
{

    /** @var \Doctrine\ODM\MongoDB\DocumentManager */
    protected $dm;

    /** @var \Symfony\Component\HttpFoundation\Request */
    protected $request;

    /** @var Builder */
    protected $queryBuilder;

    /** @var string */
    protected $entity_name;

    /** @var string */
    protected $entity_alias;

    /** @var array */
    protected $fields;

    /** @var string */
    protected $order_field = NULL;

    /** @var string */
    protected $order_type = "asc";

    /** @var string */
    protected $where = NULL;

    /** @var array */
    protected $joins = array();

    /** @var boolean */
    protected $has_action = true;

    /** @var array */
    protected $fixed_data = NULL;

    /** @var closure */
    protected $renderer = NULL;

    /** @var boolean */
    protected $search = FALSE;

        /** @var boolean */
    protected $global_search = FALSE;

    /** @var array */
    protected $global_search_fields = array();

    /** @var boolean */
    protected $date_filter = FALSE;

    /** @var array */

    protected $date_filter_fields = array();

    /** @var string */

    protected $group_by = NULL;

    /**
     * class constructor
     *
     * @param DocumentManager $dm
     * @param RequestStack $request
     */
    public function __construct(DocumentManager $dm, RequestStack $request)
    {

        $this->dm           = $dm;
        $this->request      = $request->getCurrentRequest();
        $this->queryBuilder = $this->dm->createQueryBuilder();
    }

    /**
     * get the search dql
     *
     * @return string
     */
    protected function _addSearch(Builder $queryBuilder)
    {



        if ($this->global_search == TRUE)
        {
            $request       = $this->request;

            $search_param = null;

            if ($search_params = $request->get("search")) {
                // DataTables 1.10

                if (isset($search_params['value'])) {
                    $search_param = $search_params['value'];
                }
            } else {
                $search_param = $request->get("sSearch") ;
            }

            $search_fields = array_values($this->fields);

            if ($search_param)
            {
                $where = $queryBuilder->expr();



                foreach ($this->global_search_fields as $field) {

                    $fieldlist = explode(' ',trim($search_fields[$field]));
                    $search_field = $fieldlist[0];

                   $where =  $queryBuilder->expr()->field($search_field)->equals(new Regex( $search_param,'i'));

                    $queryBuilder->addOr($where);

                }



            }

        }
    }

    /**
     * convert object to array
     * @param object $object
     * @return array
     */
    protected function _toArray($object)
    {
        $reflectionClass = new \ReflectionClass(get_class($object));
        $array           = array();
        foreach ($reflectionClass->getProperties() as $property)
        {
            $property->setAccessible(true);
            $array[$property->getName()] = $property->getValue($object);
            $property->setAccessible(false);
        }
        return $array;
    }

    /**
     * add join
     *
     * @example:
     *      ->setJoin(
     *              'r.event',
     *              'e',
     *              \Doctrine\ORM\Query\Expr\Join::INNER_JOIN,
     *              'e.name like %test%')
     *
     * @param string $join_field
     * @param string $alias
     * @param string $type
     * @param string $cond
     *
     * @return Datatable
     */
    public function addJoin($join_field, $alias, $type = Join::INNER_JOIN, $cond = '')
    {
        throw new \Exception('ODM join not supported');
    }

    /**
     * get total records
     *
     * @return integer
     */
    public function getTotalRecords()
    {
        $qb = clone $this->queryBuilder;

        $this->_addSearch($qb);

        //$this->_addSearch($qb);
        if (empty($gb) || !in_array($this->fields['_identifier_'], $gb))
        {
            return $qb->count()->getQuery()->execute();
        }
    }

    /**
     * get data
     *
     * @return array
     */
    public function getData()
    {

        $request     = $this->request;
        $dql_fields  = array_values($this->fields);
        $order_field = null;
        if ($request->get('iSortCol_0') != null)
        {
            $order_field = $dql_fields[$request->get('iSortCol_0')];
        }
        $qb = clone $this->queryBuilder;
        if (!is_null($order_field))
        {
            $qb->sort($order_field, $request->get('sSortDir_0', 'asc'));
        }
        $selectFields = $this->fields;
        foreach ($selectFields as &$field)
        {
            if (preg_match('~as~', $field))
            {
                throw new \Exception(sprintf('cannot use "as" keyword with Mongodb driver'));
            }
        }
//        $qb->select($selectFields);
        $this->_addSearch($qb);
//        $qb->hydrate(false);
        $limit = (int) $request->get('iDisplayLength');
        $skip  = (int) $request->get('iDisplayStart');
        if ($limit > 0)
        {
            $qb->skip($skip)->limit($limit);
        }
        $query                = $qb->getQuery();
        $items                = $query->execute()->toArray();
        $iTotalDisplayRecords = (string) count($items);
        $data                 = array();
        foreach ($items as $item)
        {
            $_item = array();
            $item  = $this->_toArray($item);
            foreach ($selectFields as $key => $value)
            {
                $_item[$value] = isset($item[$value]) ? $item[$value] : NULL;
            }
            $data[] = array_values($_item);
        }
        return array($data, $items);
    }

    /**
     * get entity name
     *
     * @return string
     */
    public function getEntityName()
    {
        return $this->entity_name;
    }

    /**
     * get entity alias
     *
     * @return string
     */
    public function getEntityAlias()
    {
        return $this->entity_alias;
    }

    /**
     * get fields
     *
     * @return array
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * get order field
     *
     * @return string
     */
    public function getOrderField()
    {
        return $this->order_field;
    }

    /**
     * get order type
     *
     * @return string
     */
    public function getOrderType()
    {
        return $this->order_type;
    }

    /**
     * get doctrine query builder
     *
     * @return \Doctrine\ODM\MongoDB\QueryBuilder
     */
    public function getDoctrineQueryBuilder()
    {
        return $this->queryBuilder;
    }

    /**
     * set entity
     *
     * @param type $entity_name
     * @param type $entity_alias
     *
     * @return Datatable
     */
    public function setEntity($entity_name, $entity_alias)
    {
        $this->entity_name  = $entity_name;
        $this->entity_alias = $entity_alias;
        $this->queryBuilder->find($entity_name);
        return $this;
    }

    /**
     * set fields
     *
     * @param array $fields
     *
     * @return Datatable
     */
    public function setFields(array $fields)
    {
        $this->fields = $fields;
        return $this;
    }

    /**
     * set order
     *
     * @param type $order_field
     * @param type $order_type
     *
     * @return Datatable
     */
    public function setOrder($order_field, $order_type)
    {
        $this->order_field = strtolower($order_field);
        $this->order_type  = strtolower($order_type);
        return $this;
    }

    /**
     * set fixed data
     *
     * @param type $data
     *
     * @return Datatable
     */
    public function setFixedData($data)
    {
        $this->fixed_data = $data;
        return $this;
    }

    /**
     * set query where
     *
     * @param string $where
     * @param array  $params
     *
     * @return Datatable
     */
    public function setWhere($where, array $params = array())
    {
        throw new \Exception('ODM where statement not supported');
    }

    /**
     * set query group
     *
     * @param string $group
     *
     * @return Datatable
     */
    public function setGroupBy($group)
    {
        throw new \Exception('ODM groupby statement not supported');
    }

    /**
     * set search
     *
     * @param bool $search
     *
     * @return Datatable
     */
    public function setSearch($search)
    {
        $this->search = $search;
        return $this;
    }

    /**
     * set doctrine query builder
     *
     * @param \Doctrine\ODM\MongoDB\QueryBuilder $queryBuilder
     *
     * @return DoctrineBuilder
     */
    public function setDoctrineQueryBuilder(\Doctrine\ODM\MongoDB\QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
        return $this;
    }

    public function setDateFilter($date_filter) {

    }

    public function setDateFilterFields($date_filter_fields) {

    }

    public function setGlobalSearch($global_search) {
        $this->global_search = $global_search;
        return $this;

    }

    public function setGlobalSearchFields($global_search_fields) {
        $this->global_search_fields = $global_search_fields;
        return $this;

    }


}
