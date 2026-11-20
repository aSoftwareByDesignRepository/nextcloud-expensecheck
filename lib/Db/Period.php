<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getName()
 * @method void setName(string $v)
 * @method \DateTime getStartOn()
 * @method void setStartOn(\DateTime $v)
 * @method \DateTime getEndOn()
 * @method void setEndOn(\DateTime $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method ?string getClosedBy()
 * @method void setClosedBy(?string $v)
 * @method ?\DateTime getClosedAt()
 * @method void setClosedAt(?\DateTime $v)
 * @method ?string getReopenReason()
 * @method void setReopenReason(?string $v)
 */
class Period extends Entity
{
	protected string $name = '';
	protected ?\DateTime $startOn = null;
	protected ?\DateTime $endOn = null;
	protected string $status = '';
	protected ?string $closedBy = null;
	protected ?\DateTime $closedAt = null;
	protected ?string $reopenReason = null;

	public function __construct()
	{
		$this->addType('name', 'string');
		$this->addType('startOn', 'datetime');
		$this->addType('endOn', 'datetime');
		$this->addType('status', 'string');
		$this->addType('closedBy', 'string');
		$this->addType('closedAt', 'datetime');
		$this->addType('reopenReason', 'string');
	}
}
