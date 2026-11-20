<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getBatchId()
 * @method void setBatchId(int $v)
 * @method int getClaimId()
 * @method void setClaimId(int $v)
 * @method string getPaidRef()
 * @method void setPaidRef(string $v)
 * @method \DateTime getPaidAt()
 * @method void setPaidAt(\DateTime $v)
 */
class SettlementRow extends Entity
{
	protected int $batchId = 0;
	protected int $claimId = 0;
	protected string $paidRef = '';
	protected ?\DateTime $paidAt = null;

	public function __construct()
	{
		$this->addType('batchId', 'integer');
		$this->addType('claimId', 'integer');
		$this->addType('paidRef', 'string');
		$this->addType('paidAt', 'datetime');
	}
}
