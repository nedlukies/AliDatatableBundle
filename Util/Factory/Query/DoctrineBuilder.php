<?php

namespace Ali\DatatableBundle\Util\Factory\Query;

use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DoctrineBuilder implements QueryInterface
{

    /** @var \Symfony\Component\DependencyInjection\ContainerInterface */
    protected $container;

    /** @var \Doctrine\ORM\EntityManager */
    protected $em;

    /** @var \Symfony\Component\HttpFoundation\Request */
    protected $request;

    /** @var \Doctrine\ORM\QueryBuilder */
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
    
    /** @var array */
    protected $joinConditions = array();

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
     * @param ContainerInterface $container 
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container    = $container;
        $this->em           = $this->container->get('doctrine')->getManager();
        $this->request      = $this->container->get('request');
        $this->queryBuilder = $this->em->createQueryBuilder();
    }

    /**
     * get the search dql
     * 
     * @return string
     */
    protected function _addSearch(\Doctrine\ORM\QueryBuilder $queryBuilder)
    {
        if ($this->search == TRUE)
        {
            $request       = $this->request;
            $search_fields = array_values($this->fields);
            foreach ($search_fields as $i => $search_field)
            {
                $search_param = $request->get("sSearch_{$i}");
                if ($request->get("sSearch_{$i}") !== false && !empty($search_param))
                {
                    $field = explode(' ',trim($search_field));
                    $search_field = $field[0];
                    
                    $queryBuilder->andWhere(" $search_field like '%{$request->get("sSearch_{$i}")}%' ");
                }
            }
        }
        
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
                $where = $queryBuilder->expr()->orX();
                
                
                foreach ($this->global_search_fields as $field) {
                    
                    $fieldlist = explode(' ',trim($search_fields[$field]));
                    $search_field = $fieldlist[0];
                    
                    $where->add($queryBuilder->expr()->like($search_field, "'%$search_param%'"));
                    
                }

                $queryBuilder->andWhere($where);
                
            }
            
        }
        
        if ($this->date_filter == TRUE) {
            
            
            
            foreach ($this->date_filter_fields as $field) {
                
                list($alias, $column) = explode(".", $field);
                
                if ($startDate = $request->get('startDateRange')) {
                    $this->_addWhere($queryBuilder, $alias, $queryBuilder->expr()->gte($field, "'$startDate'"));
                }
                
                if ($endDate = $request->get('endDateRange')) {
                    $this->_addWhere($queryBuilder, $alias, $queryBuilder->expr()->lte($field, "'$endDate'"));
                }
            }
   
        }
        
        $this->_addJoins($queryBuilder);
    }
    
    protected function _addWhere($queryBuilder, $alias, $condition) { 
        
        if ($alias == $this->entity_alias) {
            $queryBuilder->andWhere($condition);
            
        } else {
            if (!isset($this->joinConditions[$alias])) {
                   $this->joinConditions[$alias] = $queryBuilder->expr()->andX();
            }

            $this->joinConditions[$alias]->add($condition);

            return $this->joinConditions[$alias];
        }
    }
    
    protected function _addJoins($queryBuilder)
    {
        
        
        foreach ($this->joins as $join) {
            
            
            
            if ($join['cond']) {
                $this->_addWhere($queryBuilder, $join['alias'], $join['cond']);                
            }
            
            $join_method = $join['type'] == Join::INNER_JOIN ? "innerJoin" : "leftJoin";
            $queryBuilder->$join_method($join['join_field'], $join['alias'], 'WITH', isset($this->joinConditions[$join['alias']]) ? $this->joinConditions[$join['alias']] : null );
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
        $this->joins[] = array(
            'join_field' => $join_field,
            'alias' => $alias,
            'type' => $type,
            'cond' => $cond
        );
        
        
        
        return $this;
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
    
        $qb->resetDQLPart('orderBy');
        
        $gb = $qb->getDQLPart('groupBy');
        if (empty($gb) || !in_array($this->fields['_identifier_'], $gb))
        {
            
            $qb->select(" count({$this->fields['_identifier_']}) ");
            
            return $qb->getQuery()->getSingleScalarResult();
        }
        else
        {
            $qb->resetDQLPart('groupBy');
            $qb->select(" count(distinct {$this->fields['_identifier_']}) ");
            return $qb->getQuery()->getSingleScalarResult();
        }
    }

    /**
     * get data
     * 
     * @param int $hydration_mode
     * 
     * @return array 
     */
    public function getData($hydration_mode)
    {
        $request    = $this->request;
        $dql_fields = array_values($this->fields);
        if ($request->get('iSortCol_0') != null)
        {
            $order_field = current(explode(' as ', $dql_fields[$request->get('iSortCol_0')]));
        }
        elseif ($order = $request->get('order')) {
            
             if (preg_match('/\s*as\s*/', $dql_fields[$order[0]['column']], $matches)) {
                 
                 list($field, $order_field) = explode($matches[0], $dql_fields[$order[0]['column']]);
             } else {
                 $order_field = $dql_fields[$order[0]['column']];
             }
             
             $direction = $order[0]['dir'];
        }
        else
        {
            $order_field = null;
        }
        
        
        $qb = clone $this->queryBuilder;
        if (!is_null($order_field))
        {
            $qb->orderBy($order_field, isset($direction) ? $direction : $request->get('sSortDir_0', 'asc'));
        }
        else
        {
            $qb->resetDQLPart('orderBy');
        }
        
        
        $qb->select($dql_fields);
        $this->_addSearch($qb);
        $query          = $qb->getQuery();
        $iDisplayLength = (int) $request->get('length') ? $request->get('length') : $request->get('iDisplayLength');
        if ($iDisplayLength > 0)
        {
            $query->setMaxResults($iDisplayLength)->setFirstResult($request->get('start') ? $request->get('start') : $request->get('iDisplayStart'));
        }
        
        $objects      = $query->getResult(Query::HYDRATE_OBJECT);
        $selectFields = array();
        
        foreach ($this->fields as $label => $selector)
        {
            $has_alias      = preg_match_all('~([A-z]?\.[A-z]+)?\sas\s(.*)~', $selector, $matches);
           
            
            if ($has_alias) {
                $selectFields[] = $matches[2][0];
            } else {
                $selectFields[] = substr($selector, strpos($selector, '.') + 1);
            }
           
            
        }
        
        
        
      
        $data = array();
        foreach ($objects as $object)
        {
            $d   = array();
            if (!is_array($object)) {
                
                $map = $this->_toArray($object);
            } else {
                $map = $object;
            }
            
            foreach ($selectFields as $key)
            {
                $d[] = $map[$key];
            }
            $data[] = $d;
        }
        return array($data, $objects);
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
     * @return \Doctrine\ORM\QueryBuilder
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
        $this->queryBuilder->from($entity_name, $entity_alias);
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
        $this->queryBuilder->select(implode(', ', $fields));
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
     
        $this->order_field = $order_field;
        $this->order_type  = $order_type;
        $this->queryBuilder->orderBy($order_field, $order_type);
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
        $this->queryBuilder->where($where);
        $this->queryBuilder->setParameters($params);
        return $this;
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
        $this->queryBuilder->groupBy($group);
        return $this;
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
     * set global search
     * 
     * @param bool $search
     * 
     * @return Datatable
     */
    public function setGlobalSearch($global_search)
    {
        $this->global_search = $global_search;
        return $this;
    }
    

    /**
     * 
     * @param array $global_search_fields
     * @return \Ali\DatatableBundle\Util\Factory\Query\DoctrineBuilder
     */
    public function setGlobalSearchFields($global_search_fields) {
        $this->global_search_fields = $global_search_fields;
        return $this;
    }

    
     /**
     * set date_filter
     * 
     * @param bool $search
     * 
     * @return Datatable
     */
    public function setDateFilter($date_filter)
    {
        $this->date_filter = $date_filter;
        return $this;
    }
    

    /**
     * 
     * @param array $date_filter_fields
     * @return \Ali\DatatableBundle\Util\Factory\Query\DoctrineBuilder
     */
    public function setDateFilterFields($date_filter_fields) {
        $this->date_filter_fields = $date_filter_fields;
        return $this;
    }
    
    /**
     * set doctrine query builder
     * 
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     * 
     * @return DoctrineBuilder 
     */
    public function setDoctrineQueryBuilder(\Doctrine\ORM\QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
        return $this;
    }

}
