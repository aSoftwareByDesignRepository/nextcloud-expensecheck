<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getRateType()
 * @method void setRateType(string $v)
 * @method string getCode()
 * @method void setCode(string $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method string getCurrency()
 * @method void setCurrency(string $v)
 * @method int getRateMinor()
 * @method void setRateMinor(int $v)
 * @method \DateTime getEffectiveFrom()
 * @method void setEffectiveFrom(\DateTime $v)
 * @method int getActive()
 * @method void setActive(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class RateEntry extends Entity
{
	protected string $rateType = '';
	protected string $code = '';
	protected string $name = '';
	protected string $currency = '';
	protected int $rateMinor = 0;
	protected ?\DateTime $effectiveFrom = null;
	protected int $active = 0;
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('rateType', 'string');
		$this->addType('code', 'string');
		$this->addType('name', 'string');
		$this->addType('currency', 'string');
		$this->addType('rateMinor', 'integer');
		$this->addType('effectiveFrom', 'datetime');
		$this->addType('active', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
