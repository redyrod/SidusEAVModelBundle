<?php

namespace Sidus\EAVModelBundle\Entity;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query\Expr\Join;
use Sidus\EAVModelBundle\Model\FamilyInterface;

/**
 * Base repository for Data, not currently used
 *
 * @author Vincent Chalnot <vincent@sidus.fr>
 */
class DataRepository extends EntityRepository
{
    /**
     * @param FamilyInterface $family
     * @param int|string      $reference
     * @param bool            $idFallback
     * @return null|DataInterface
     * @throws NonUniqueResultException
     */
    public function findByIdentifier(FamilyInterface $family, $reference, $idFallback = false)
    {
        $identifierAttribute = $family->getAttributeAsIdentifier();
        if (!$identifierAttribute) {
            if (!$idFallback) {
                return null;
            }

            return $this->findOneBy([
                'id' => $reference,
                'family' => $family->getCode(),
            ]);
        }
        $qb = $this->createQueryBuilder('d');
        $dataBaseType = $identifierAttribute->getType()->getDatabaseType();
        $joinCondition = "(id.attributeCode = :attributeCode AND id.{$dataBaseType} = :reference)";
        $qb
            ->addSelect('values')
            ->join('d.values', 'id', Join::WITH, $joinCondition)
            ->join('d.values', 'values')
            ->where('d.family = :familyCode')
            ->setParameters([
                'attributeCode' => $identifierAttribute->getCode(),
                'reference' => $reference,
                'familyCode' => $family->getCode(),
            ])
        ;

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @param FamilyInterface $family
     * @return DataInterface
     */
    public function getInstance(FamilyInterface $family)
    {
        if (!$family->isSingleton()) {
            throw new \LogicException("Family {$family->getCode()} is not a singleton");
        }
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.family = :familyCode')
            ->addSelect('values')
            ->join('d.values', 'values')
            ->setParameters([
                'familyCode' => $family->getCode(),
            ])
        ;

        $instance = $qb->getQuery()->getOneOrNullResult();
        if (!$instance) {
            $dataClass = $family->getDataClass();
            $instance = new $dataClass($family);
        }

        return $instance;
    }
}
