<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getCurrency()
 * @method void setCurrency(string $v)
 * @method string getRate()
 * @method void setRate(string $v)
 * @method \DateTime getEffectiveFrom()
 * @method void setEffectiveFrom(\DateTime $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class FxRate extends Entity
{
	protected string $currency = '';
	protected string $rate = '';
	protected ?\DateTime $effectiveFrom = null;
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('currency', 'string');
		$this->addType('rate', 'string');
		$this->addType('effectiveFrom', 'datetime');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
