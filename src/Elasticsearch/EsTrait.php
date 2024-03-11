<?php

namespace fairwic\MongoOrm\Elasticsearch;

trait EsTrait
{
    use EsSyncTrait;

    /**
     * @param $id
     * @return bool
     * @throws \Exception
     */
    function searchable($id, $data = []): bool
    {
        //get data from mongo
        if (empty($data)) {
            $data = $this->findById($id, $this->getEsFields())->toArray();
        }
        //sync data to es
        return $this->syncOneToEs($id, $data);
    }

    /**
     * @param $ids
     * @return bool
     * @throws \Exception
     */
    function searchableMany($ids): bool
    {
        //get data from mongo
        $data = $this->whereIn('id', $ids)->select($this->getEsFields())->get();
        //sync data to es
        return $this->syncBatch($data);
    }
}
