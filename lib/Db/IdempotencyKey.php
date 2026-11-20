<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getKey()
 * @method void setKey(string $v)
 * @method string getUserId()
 * @method void setUserId(string $v)
 * @method ?int getClaimId()
 * @method void setClaimId(?int $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class IdempotencyKey extends Entity
{
	protected string $key = '';
	protected string $userId = '';
	protected ?int $claimId = null;
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('key', 'string');
		$this->addType('userId', 'string');
		$this->addType('claimId', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}
