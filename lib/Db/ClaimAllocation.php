<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getLineId()
 * @method void setLineId(int $v)
 * @method int getCostCenterId()
 * @method void setCostCenterId(int $v)
 * @method ?string getSharePercent()
 * @method void setSharePercent(?string $v)
 * @method ?int getAmountMinor()
 * @method void setAmountMinor(?int $v)
 * @method ?int getPcProjectId()
 * @method void setPcProjectId(?int $v)
 */
class ClaimAllocation extends Entity
{
	protected int $lineId = 0;
	protected int $costCenterId = 0;
	protected ?string $sharePercent = null;
	protected ?int $amountMinor = null;
	protected ?int $pcProjectId = null;

	public function __construct()
	{
		$this->addType('lineId', 'integer');
		$this->addType('costCenterId', 'integer');
		$this->addType('sharePercent', 'string');
		$this->addType('amountMinor', 'integer');
		$this->addType('pcProjectId', 'integer');
	}
}
