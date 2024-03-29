<?php

namespace fairwic\MongoOrm\Elasticsearch;

use Elasticsearch\Client;
use Hyperf\Elasticsearch\ClientBuilderFactory;

class Es
{
    protected Client $es_client;

    public function __construct()
    {
        $clientFactory = new ClientBuilderFactory();
        $this->es_client = $clientFactory->create()->setHosts([env('ELASTICSEARCH_HOST', 'http://127.0.0.1:9201')])->build();
    }

    /**
     * 判断索引是否存在
     **/

    public function indexExistsEs($index)
    {
        $params = [
            'index' => $index,
        ];
        $result = $this->es_client->indices()->exists($params);
        return $result;
    }

    /**
     * 创建索引
     **/
    public function createIndex($index)
    {
        $params = [
            'index' => $index,
            'settings' => [
                //分片数
                'number_of_shards' => (env('APP_ENV') == 'pre') ? 1 : (int)env('NUMBER_OF_SHARDS', 5),
                //副本
                'number_of_replicas' => (int)env('NUMBER_OF_REPLICA', 1)
            ]
        ];
        $result = $this->es_client->indices()->create($params);
        return $result;
    }

    /**
     * 设置mapping
     **/
    public function putMapping($params)
    {
        extract($params);
        $mapping['index'] = $index;
        $mapping['type'] = $type;
        $field_type['keyword'] = [
            'type' => 'keyword',
        ];
        $field_type['text'] = [
            'type' => 'text',
            'analyzer' => 'ik_max_word',
            'search_analyzer' => 'ik_max_word',
        ];
        $data = [
            'properties' => value(function () use ($mapping_key, $field_type) {
                $properties = [];
                foreach ($mapping_key as $key => $value) {
                    if (empty($value)) {
                        continue;
                    }
                    foreach ($value as $cvalue) {
                        $properties[$cvalue] = $field_type[$key];
                    }
                }
                return $properties;
            })
        ];
        $mapping['body'] = $data;
        return $this->es_client->indices()->putMapping($mapping);
    }

    /**
     * 判断文档是否存在
     **/
    public function existsEs($params)
    {
        return $this->es_client->exists($params);
    }

    /**
     * 新建/替换文档
     *
     * @throws \Exception
     */
    public function indexEs($params): bool
    {
        extract($params);
        $index_data = [
            'index' => $index,
            'id' => $id,
            'body' => $body,
        ];
        $result = $this->es_client->index($index_data);
        if (!$result) {
            throw new \Exception('Es index error result is null');
        } elseif (isset($result['errors']) && !$result['errors']) {
            throw new \Exception('Es index error');
        }
        return true;
    }

    /**
     * 批量更新
     * @param $params
     * @return bool
     * @throws \Exception
     */
    public function bulk($params): bool
    {
        //执行bulk操作
        $result = $this->es_client->bulk($params);
        if (!$result) {
            throw new \Exception('Es update error,result is null');
        } elseif (!$result['errors']) {
            return true;
        }
        return true;
    }

    /**
     * 修改文档
     **/
    public function deleteEs($params)
    {
        extract($params);
        $delete_data = [
            'index' => $index,
            'id' => $id,
        ];
        return $this->es_client->delete($delete_data);
    }

    /**
     * 查询数据
     * 请求参数实例：
     * $es_params['data']: 详细数据 键值对形式
     * $es_params['condition']['must_field']: es bool查询must对应字段 ['terms_field'=>['id','pid'],'range_field'=>['ctime','age']] 等
     * $es_params['condition']['should_field']: es bool查询should对应字段 ['terms_field'=>['id','pid'],'range_field'=>['ctime','age']] 等
     * $es_params['source_field']: es 查询需要获取的字段
     * $es_params['field_alias']: 查询字段别名 例如 'field'=>[{'a'=>'123'}] 查询时候拼接 field.a=*
     */

    public function searchEs($es_params)
    {
        extract($es_params);
        $params = [
            'index' => $index,
            'body' => $body,
        ];
        return $this->es_client->search($params);
    }

    /**
     * 查询数据
     * 请求参数实例：
     * $es_params['data']: 详细数据 键值对形式
     * $es_params['must_field']: es bool查询must对应字段 ['terms_field'=>['id','pid'],'range_field'=>['ctime','age']] 等
     * $es_params['should_field']: es bool查询should对应字段 ['terms_field'=>['id','pid'],'range_field'=>['ctime','age']] 等
     * $es_params['source_field']: es 查询需要获取的字段
     * $es_params['field_alias']: 查询字段别名 例如 ['a'=>['b','c','d']] 查询时候拼接 a.b=* a.c=*
     */

    public function new_searchEs($es_params)
    {
        extract($es_params);
        if (!isset($field_alias)) {
            $field_alias = [];
        }
        $offset = $data['offset'] ?? 0;
        $limit = $data['limit'] ?? 50;
        $bool = [];
        if (isset($must_field)) {
            $must_condition = $this->_getCondition($data, $must_field, $field_alias);
            if (!empty($must_condition)) {
                $bool['must'] = $must_condition;
            }
        }
        if (isset($should_field)) {
            $should_condition = $this->_getCondition($data, $should_field, $field_alias);
            if (!empty($should_condition)) {
                $bool['should'] = $should_condition;
                $bool['minimum_should_match'] = 1;
            }
        }
        $body = ['from' => $offset, 'size' => $limit];
        if (!empty($bool)) {
            $body['query'] = ['bool' => $bool];
        }
        if (!empty($source_field)) {
            $body['_source'] = $source_field;
        }
        $params = [
            'index' => $index,
            'type' => $type,
            'body' => $body,
        ];
        return $this->es_client->search($params);
    }

    public function deleteByQueryEs($params)
    {
        extract($params);
        $query = $this->_getQueryInfo($condition_data, $condition, $field_alias);
        $body = ['query' => $query];
        $delete_data = [
            'index' => $index,
            'body' => $body,
        ];
        return $this->es_client->deleteByQuery($delete_data);
    }

    /**
     * 整理bool查询条件
     * 请求参数实例：
     * $es_params['data']: 详细数据 键值对形式
     * $condition['must_field']: es bool查询must对应字段 ['terms_field'=>['id','pid'],'range_field'=>['ctime','age']] 等
     * $condition['should_field']: es bool查询should对应字段 ['terms_field'=>['id','pid'],'range_field'=>['ctime','age']] 等
     * $field_alias: 查询字段别名 例如 ['a'=>['b','c','d']] 查询时候拼接 a.b=* a.c=*
     */
    private function _getQueryInfo($data, $condition, $field_alias)
    {
        extract($condition);
        $bool = [];
        if (isset($must_field)) {
            $must_condition = $this->_getCondition($data, $must_field, $field_alias);
            if (!empty($must_condition)) {
                $bool['must'] = $must_condition;
            }
        }
        if (isset($should_field)) {
            $should_condition = $this->_getCondition($data, $should_field, $field_alias);
            if (!empty($should_condition)) {
                $bool['should'] = $should_condition;
                $bool['minimum_should_match'] = 1;
            }
        }
        return $bool;
    }

    private function _getCondition($data, $condition_field, $field_alias = [])
    {
        extract($condition_field);
        $condition = [];
        foreach ($data as $key => &$value) {
            $middle_key = $key;
            if (!empty($field_alias)) {
                foreach ($field_alias as $alias_key => $alias_value) {
                    if (in_array($key, $alias_value)) {
                        $middle_key = $alias_key . '.' . $key;
                        break;
                    }
                }
            }
            if (isset($terms_field) && in_array($key, $terms_field)) {
                if (is_int($value)) {
                    $value = (string)$value;
                }
                $condition[] = ['terms' => [$middle_key => explode(',', $value)]];
                continue;
            }
            if (isset($range_field) && in_array($key, $range_field)) {
                list($from, $to) = $value;
                if ($from == 0) {
                    $condition[] = ['range' => [$middle_key => ['lte' => $to]]];
                } else if ($to == 0) {
                    $condition[] = ['range' => [$middle_key => ['gte' => $from]]];
                } else {
                    $condition[] = ['range' => [$middle_key => ['gte' => $from, 'lte' => $to]]];
                }
                continue;
            }
            if (isset($match_field) && in_array($key, $match_field)) {
                $condition[] = ['match' => [$middle_key => $value]];
            }
        }
        return $condition;
    }
}