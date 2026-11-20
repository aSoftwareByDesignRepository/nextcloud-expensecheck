<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getClaimId()
 * @method void setClaimId(int $v)
 * @method int getSeq()
 * @method void setSeq(int $v)
 * @method ?string getActor()
 * @method void setActor(?string $v)
 * @method string getEvent()
 * @method void setEvent(string $v)
 * @method ?string getFromStatus()
 * @method void setFromStatus(?string $v)
 * @method ?string getToStatus()
 * @method void setToStatus(?string $v)
 * @method ?string getPayload()
 * @method void setPayload(?string $v)
 * @method string getPayloadHash()
 * @method void setPayloadHash(string $v)
 * @method string getPrevHash()
 * @method void setPrevHash(string $v)
 * @method string getEntryHash()
 * @method void setEntryHash(string $v)
 * @method int getTombstoned()
 * @method void setTombstoned(int $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class ClaimEvent extends Entity
{
	protected int $claimId = 0;
	protected int $seq = 0;
	protected ?string $actor = null;
	protected string $event = '';
	protected ?string $fromStatus = null;
	protected ?string $toStatus = null;
	protected ?string $payload = null;
	protected string $payloadHash = '';
	protected string $prevHash = '';
	protected string $entryHash = '';
	protected int $tombstoned = 0;
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('claimId', 'integer');
		$this->addType('seq', 'integer');
		$this->addType('actor', 'string');
		$this->addType('event', 'string');
		$this->addType('fromStatus', 'string');
		$this->addType('toStatus', 'string');
		$this->addType('payload', 'string');
		$this->addType('payloadHash', 'string');
		$this->addType('prevHash', 'string');
		$this->addType('entryHash', 'string');
		$this->addType('tombstoned', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}
