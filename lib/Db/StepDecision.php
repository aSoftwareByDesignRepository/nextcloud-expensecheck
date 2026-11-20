<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getClaimId()
 * @method void setClaimId(int $v)
 * @method int getStepNo()
 * @method void setStepNo(int $v)
 * @method string getDecidedBy()
 * @method void setDecidedBy(string $v)
 * @method string getDecision()
 * @method void setDecision(string $v)
 * @method ?string getNote()
 * @method void setNote(?string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class StepDecision extends Entity
{
	protected int $claimId = 0;
	protected int $stepNo = 0;
	protected string $decidedBy = '';
	protected string $decision = '';
	protected ?string $note = null;
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('claimId', 'integer');
		$this->addType('stepNo', 'integer');
		$this->addType('decidedBy', 'string');
		$this->addType('decision', 'string');
		$this->addType('note', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
