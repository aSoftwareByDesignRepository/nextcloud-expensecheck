<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $v)
 * @method string getAssignedBy()
 * @method void setAssignedBy(string $v)
 * @method \DateTime getAssignedAt()
 * @method void setAssignedAt(\DateTime $v)
 */
class MobileSeat extends Entity
{
	protected string $userId = '';
	protected string $assignedBy = '';
	protected ?\DateTime $assignedAt = null;

	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('assignedBy', 'string');
		$this->addType('assignedAt', 'datetime');
	}
}
