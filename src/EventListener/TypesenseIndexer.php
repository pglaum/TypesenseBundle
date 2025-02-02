<?php

declare(strict_types=1);

namespace ACSEO\TypesenseBundle\EventListener;

use ACSEO\TypesenseBundle\Manager\CollectionManager;
use ACSEO\TypesenseBundle\Manager\DocumentManager;
use ACSEO\TypesenseBundle\Transformer\DoctrineToTypesenseTransformer;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\Persistence\Event\LifecycleEventArgs;

class TypesenseIndexer
{
    private $managedClassNames                     = [];
    private $objectIdsThatCanBeDeletedByObjectHash = [];
    private $documentsToIndex                      = [];
    private $documentsToUpdate                     = [];
    private $documentsToDelete                     = [];

    public function __construct(
        protected CollectionManager $collectionManager,
        protected DocumentManager $documentManager,
        protected DoctrineToTypesenseTransformer $transformer
    ) {
        $this->managedClassNames = $this->collectionManager->getManagedClassNames();
    }

    public function postPersist(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();

        if ($this->entityIsNotManaged($entity)) {
            return;
        }

        $collection = $this->getCollectionName($entity);
        $data       = $this->transformer->convert($entity);

        $this->documentsToIndex[$collection] ??= [];
        $this->documentsToIndex[$collection][] = $data;
    }

    public function postUpdate(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();

        if ($this->entityIsNotManaged($entity)) {
            return;
        }

        $collectionDefinitionKey = $this->getCollectionKey($entity);
        $collectionConfig        = $this->collectionManager->getCollectionDefinitions()[$collectionDefinitionKey];

        $this->checkPrimaryKeyExists($collectionConfig);

        $collection = $this->getCollectionName($entity);
        $data       = $this->transformer->convert($entity);

        $this->documentsToUpdate[$collection] ??= [];
        $this->documentsToUpdate[$collection][] = $data;
    }

    private function checkPrimaryKeyExists($collectionConfig)
    {
        foreach ($collectionConfig['fields'] as $config) {
            if ($config['type'] === 'primary') {
                return;
            }
        }

        throw new \Exception(sprintf('Primary key info have not been found for Typesense collection %s', $collectionConfig['typesense_name']));
    }

    public function preRemove(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();

        if ($this->entityIsNotManaged($entity)) {
            return;
        }

        $data = $this->transformer->convert($entity);

        $this->objectIdsThatCanBeDeletedByObjectHash[spl_object_hash($entity)] = $data['id'];
    }

    public function postRemove(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();

        $entityHash = spl_object_hash($entity);

        if (!isset($this->objectIdsThatCanBeDeletedByObjectHash[$entityHash])) {
            return;
        }

        $collection = $this->getCollectionName($entity);

        $this->documentsToDelete[] = [$collection, $this->objectIdsThatCanBeDeletedByObjectHash[$entityHash]];
    }

    public function postFlush()
    {
        $this->indexDocuments();
        $this->updateDocuments();
        $this->deleteDocuments();

        $this->resetDocuments();
    }

    private function indexDocuments()
    {
        foreach ($this->documentsToIndex as $collection => $documents) {
            $this->documentManager->import($collection, $documents);
        }
    }

    private function updateDocuments()
    {
        foreach ($this->documentsToUpdate as $collection => $documents) {
            $this->documentManager->import($collection, $documents, 'upsert');
        }
    }

    private function deleteDocuments()
    {
        foreach ($this->documentsToDelete as $documentToDelete) {
            $this->documentManager->delete(...$documentToDelete);
        }
    }

    private function resetDocuments()
    {
        $this->documentsToIndex  = [];
        $this->documentsToUpdate = [];
        $this->documentsToDelete = [];
    }

    private function entityIsNotManaged($entity)
    {
        $entityClassname = ClassUtils::getClass($entity);

        return !in_array($entityClassname, array_values($this->managedClassNames), true);
    }

    private function getCollectionName($entity)
    {
        $entityClassname = ClassUtils::getClass($entity);

        return array_search($entityClassname, $this->managedClassNames, true);
    }

    private function getCollectionKey($entity)
    {
        $entityClassname = ClassUtils::getClass($entity);

        foreach ($this->collectionManager->getCollectionDefinitions() as $key => $def) {
            if ($def['entity'] === $entityClassname) {
                return $key;
            }
        }
    }
}
