<?php

namespace Sidus\EAVModelBundle\Entity;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Exception;
use JMS\Serializer\Annotation as JMS;
use LogicException;
use Sidus\EAVModelBundle\Model\AttributeInterface;
use Sidus\EAVModelBundle\Model\FamilyInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use UnexpectedValueException;
use Sidus\EAVModelBundle\Validator\Constraints\Data as DataConstraint;

/**
 * @DataConstraint()
 */
abstract class Data
{
    /**
     * @var int
     *
     * @ORM\Id
     * @ORM\Column(name="id", type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var Data
     * @ORM\ManyToOne(targetEntity="Sidus\EAVModelBundle\Entity\Data", inversedBy="children")
     * @ORM\JoinColumn(name="parent_id", referencedColumnName="id", onDelete="cascade")
     */
    protected $parent;

    /**
     * @var Data[]
     * @ORM\OneToMany(targetEntity="Sidus\EAVModelBundle\Entity\Data", mappedBy="parent", cascade={"persist", "remove"}, orphanRemoval=true)
     */
    protected $children;

    /**
     * @var Value[]|Collection
     * @ORM\OneToMany(targetEntity="Sidus\EAVModelBundle\Entity\Value", mappedBy="data", cascade={"persist", "remove"}, fetch="EAGER", orphanRemoval=true)
     * @ORM\OrderBy({"position" = "ASC"})
     * @JMS\Exclude()
     */
    protected $values;

    /**
     * @var DateTime
     * @ORM\Column(name="created_at", type="datetime")
     */
    protected $createdAt;

    /**
     * @var DateTime
     * @ORM\Column(name="updated_at", type="datetime")
     */
    protected $updatedAt;

    /**
     * @var FamilyInterface
     * @ORM\Column(name="family_code", type="sidus_family", length=255)
     * @JMS\Exclude()
     */
    protected $family;

    /**
     * @var int
     * @ORM\Column(name="current_version", type="integer")
     */
    protected $currentVersion = 0;

    /**
     * @var Context
     */
    protected $currentContext;

    /**
     * Initialize the data with an optional (but recommended family code)
     *
     * @param FamilyInterface $family
     * @throws LogicException
     */
    public function __construct(FamilyInterface $family)
    {
        if (!$family->isInstantiable()) {
            throw new LogicException("Family {$family->getCode()} is not instantiable");
        }
        $this->family = $family;
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
        $this->values = new ArrayCollection();
        $this->children = new ArrayCollection();
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param $id
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @param DateTime $createdAt
     * @return Data
     */
    public function setCreatedAt(DateTime $createdAt = null)
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return DateTime
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * @param DateTime $updatedAt
     * @return Data
     */
    public function setUpdatedAt(DateTime $updatedAt = null)
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @return DateTime
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }

    /**
     * @param Data $parent
     * @return Data
     */
    public function setParent(Data $parent = null)
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * @return Data
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * @JMS\VirtualProperty
     * @JMS\SerializedName("family")
     * @return string
     */
    public function getFamilyCode()
    {
        return $this->getFamily()->getCode();
    }

    /**
     * Return all values matching the attribute code
     *
     * @param AttributeInterface|null $attribute
     * @param ContextInterface $context
     * @return Collection|Value[]
     */
    public function getValues(AttributeInterface $attribute = null, ContextInterface $context = null)
    {
        if (null === $attribute) {
            return $this->values;
        }
        $this->checkAttribute($attribute);
        if (!$context) {
            $context = $this->getCurrentContext();
        }
        $values = new ArrayCollection();
        foreach ($this->values as $value) {
            if ($value->getAttributeCode() === $attribute->getCode() && $attribute->isContextMatching($value, $context)) {
                $values->add($value);
            }
        }
        return $values;
    }

    /**
     * Return first value found for attribute code in value collection
     *
     * @param AttributeInterface $attribute
     * @param ContextInterface $context
     * @return null|Value
     */
    public function getValue(AttributeInterface $attribute, ContextInterface $context = null)
    {
        $values = $this->getValues($attribute, $context);
        return count($values) === 0 ? null : $values->first();
    }

    /**
     * Get the value data of the value matching the attribute
     *
     * @param AttributeInterface $attribute
     * @param ContextInterface $context
     * @return mixed
     * @throws \Exception
     */
    public function getValueData(AttributeInterface $attribute, ContextInterface $context = null)
    {
        $valuesData = $this->getValuesData($attribute, $context);
        return count($valuesData) === 0 ? null : $valuesData->first();
    }

    /**
     * Get the values data of multiple values for a given attribute
     *
     * @param AttributeInterface $attribute
     * @param ContextInterface $context
     * @return mixed
     * @throws \Exception
     */
    public function getValuesData(AttributeInterface $attribute = null, ContextInterface $context = null)
    {
        $valuesData = new ArrayCollection();
        $accessor = PropertyAccess::createPropertyAccessor();
        foreach ($this->getValues($attribute, $context) as $value) {
            $valuesData->add($accessor->getValue($value, $attribute->getType()->getDatabaseType()));
        }
        return $valuesData;
    }

    /**
     * Set the value's data of a given attribute
     *
     * @param AttributeInterface $attribute
     * @param mixed $dataValue
     * @param ContextInterface $context
     * @return Data
     * @throws Exception
     */
    public function setValueData(AttributeInterface $attribute, $dataValue, ContextInterface $context = null)
    {
        return $this->setValuesData($attribute, [$dataValue], $context);
    }

    /**
     * Set the values' data of a given attribute for multiple fields
     *
     * @param AttributeInterface $attribute
     * @param array|\Traversable $dataValues
     * @param ContextInterface $context
     * @return Data
     * @throws Exception
     */
    public function setValuesData(AttributeInterface $attribute, $dataValues, ContextInterface $context = null)
    {
        if (!is_array($dataValues) && !$dataValues instanceof \Traversable) {
            throw new UnexpectedValueException('Datas must be an array or implements Traversable');
        }
        $this->emptyValues($attribute, $context);
        $accessor = PropertyAccess::createPropertyAccessor();
        $position = 0;
        foreach ($dataValues as $dataValue) {
            /** @noinspection DisconnectedForeachInstructionInspection */
            $value = $this->createValue($attribute, $context);
            $value->setPosition($position++);
            $accessor->setValue($value, $attribute->getType()->getDatabaseType(), $dataValue);
        }
        return $this;
    }

    /**
     * @param AttributeInterface $attribute
     * @param ContextInterface $context
     * @return Data
     */
    public function emptyValues(AttributeInterface $attribute, ContextInterface $context = null)
    {
        $values = $this->getValues($attribute, $context);
        foreach ($values as $value) {
            $this->removeValue($value);
        }
        return $this;
    }

    /**
     * @param Value $value
     * @return $this
     */
    public function addValue(Value $value)
    {
        $value->setData($this);
        return $this;
    }

    /**
     * Append data to an attribute
     *
     * @param AttributeInterface $attribute
     * @param $valueData
     * @param ContextInterface $context
     * @return Data
     * @throws \Exception
     */
    public function addValueData(AttributeInterface $attribute, $valueData, ContextInterface $context = null)
    {
        $newValue = $this->createValue($attribute, $context);
        $accessor = PropertyAccess::createPropertyAccessor();
        $position = -1;
        foreach ($this->getValues($attribute, $context) as $value) {
            $position = max($position, $value->getPosition());
        }
        $newValue->setPosition($position + 1);
        $accessor->setValue($newValue, $attribute->getType()->getDatabaseType(), $valueData);
        return $this;
    }

    /**
     * @param Value $value
     * @return Data
     */
    public function removeValue(Value $value)
    {
        $this->values->removeElement($value);
        $value->setData(null);
        return $this;
    }

    /**
     * @return string
     * @throws \Exception
     */
    protected function getLabelValue()
    {
        return (string) $this->getValueData($this->getFamily()->getAttributeAsLabel());
    }

    /**
     * @return string
     */
    public function getLabel()
    {
        try {
            return $this->getLabelValue();
        } catch (Exception $e) {
            return "[{$this->getId()}]";
        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        try {
            return $this->getLabelValue();
        } catch (Exception $e) {
            return '';
        }
    }

    public function __call($methodName, $arguments)
    {
        if (substr($methodName, 0, 3) === 'get') {
            return $this->__get(lcfirst(substr($methodName, 3)));
        }
        $attribute = $this->getAttribute($methodName);
        if ($attribute) {
            return $this->__get(lcfirst($methodName));
        }
        $class = get_class($this);
        throw new \BadMethodCallException("Method '{$methodName}' for object '{$class}' with family '{$this->getFamilyCode()}' does not exist");
    }

    /**
     * Used to seemingly get values as if they were normal properties of this class
     *
     * @param string $name
     * @return mixed|null|Value
     * @throws \Exception
     */
    public function __get($name)
    {
        $attributeCode = $name;
        $returnData = true;
        if (substr($name, -5) === 'Value') {
            $returnData = false;
            $attributeCode = substr($name, 0, -5);
        }
        $attribute = $this->getAttribute($attributeCode);
        if (!$attribute) {
            throw new \BadMethodCallException("No attribute or method named {$name}");
        }

        if ($attribute->isMultiple()) {
            if ($returnData) {
                return $this->getValuesData($attribute);
            }
            return $this->getValues($attribute);
        }
        if ($returnData) {
            return $this->getValueData($attribute);
        }
        return $this->getValue($attribute);
    }

    /**
     * Used to seemingly set values as if they were normal properties of this class
     *
     * @param string $name
     * @param mixed|null|Value $value
     * @throws \Exception
     */
    public function __set($name, $value)
    {
        $attributeCode = $name;
        $setData = true;
        if (substr($name, -5) === 'Value') {
            $setData = false;
            $attributeCode = substr($name, 0, -5);
        }
        $attribute = $this->getAttribute($attributeCode);
        if (!$attribute) {
            throw new \BadMethodCallException("No attribute or method named {$name}");
        }

        if ($attribute->isMultiple()) {
            if ($setData) {
                $this->setValuesData($attribute, $value);
                return;
            }
            foreach ($value as $v) {
                $this->addValue($v);
            }
            return;
        }
        if ($setData) {
            $this->setValueData($attribute, $value);
            return;
        }
        $this->addValue($value);
    }

    /**
     * @param $attributeCode
     * @return AttributeInterface
     */
    protected function getAttribute($attributeCode)
    {
        return $this->getFamily()->getAttribute($attributeCode);
    }

    /**
     * @return FamilyInterface
     */
    public function getFamily()
    {
        return $this->family;
    }

    /**
     * @param AttributeInterface $attribute
     * @param ContextInterface $context
     * @return Value
     */
    public function createValue(AttributeInterface $attribute, ContextInterface $context = null)
    {
        $value = $this->getFamily()->createValue($this, $attribute);

        if (count($attribute->getContextMask())) {
            if (!$context) {
                $context = $this->getCurrentContext();
            }
            $contextValues = [];
            foreach ($attribute->getContextMask() as $key) {
                $contextValues[$key] = $context->getContextValue($key);
            }
            $value->setContext(new Context($contextValues));
        }

        return $value;
    }

    /**
     * @param AttributeInterface $attribute
     * @param ContextInterface $context
     * @return bool
     */
    public function isEmpty(AttributeInterface $attribute, ContextInterface $context = null)
    {
        foreach ($this->getValuesData($attribute, $context) as $valueData) {
            if ($valueData !== null && $valueData !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param AttributeInterface $attribute
     * @throw UnexpectedValueException
     * @throws UnexpectedValueException
     */
    protected function checkAttribute(AttributeInterface $attribute)
    {
        if (!$this->getFamily()->hasAttribute($attribute->getCode())) {
            throw new UnexpectedValueException("Attribute {$attribute->getCode()} doesn't exists in family {$this->getFamilyCode()}");
        }
    }

    /**
     * @return int
     */
    public function getCurrentVersion()
    {
        return $this->currentVersion;
    }

    /**
     * @param int $currentVersion
     */
    public function setCurrentVersion($currentVersion)
    {
        $this->currentVersion = $currentVersion;
    }

    /**
     * @return Context
     */
    public function getCurrentContext()
    {
        if (!$this->currentContext) {
            return $this->getFamily()->getDefaultContext();
        }
        return $this->currentContext;
    }

    /**
     * @param Context $currentContext
     */
    public function setCurrentContext(Context $currentContext)
    {
        $this->currentContext = $currentContext;
    }
}
