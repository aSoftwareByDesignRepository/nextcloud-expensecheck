<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getDedupeKey()
 * @method void setDedupeKey(string $v)
 * @method string getEvent()
 * @method void setEvent(string $v)
 * @method ?int getClaimId()
 * @method void setClaimId(?int $v)
 * @method string getRecipient()
 * @method void setRecipient(string $v)
 * @method string getChannel()
 * @method void setChannel(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class NotificationLogEntry extends Entity
{
	protected string $dedupeKey = '';
	protected string $event = '';
	protected ?int $claimId = null;
	protected string $recipient = '';
	protected string $channel = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('dedupeKey', 'string');
		$this->addType('event', 'string');
		$this->addType('claimId', 'integer');
		$this->addType('recipient', 'string');
		$this->addType('channel', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
