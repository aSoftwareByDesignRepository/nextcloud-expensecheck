<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getActor()
 * @method void setActor(string $v)
 * @method string getAction()
 * @method void setAction(string $v)
 * @method ?string getPayload()
 * @method void setPayload(?string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class AuditLogEntry extends Entity
{
	protected string $actor = '';
	protected string $action = '';
	protected ?string $payload = null;
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('actor', 'string');
		$this->addType('action', 'string');
		$this->addType('payload', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
