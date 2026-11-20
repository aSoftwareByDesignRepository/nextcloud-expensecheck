<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getManagerUid()
 * @method void setManagerUid(string $v)
 * @method string getDelegateUid()
 * @method void setDelegateUid(string $v)
 * @method \DateTime getValidFrom()
 * @method void setValidFrom(\DateTime $v)
 * @method \DateTime getValidTo()
 * @method void setValidTo(\DateTime $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method ?\DateTime getRevokedAt()
 * @method void setRevokedAt(?\DateTime $v)
 */
class Delegate extends Entity
{
	protected string $managerUid = '';
	protected string $delegateUid = '';
	protected ?\DateTime $validFrom = null;
	protected ?\DateTime $validTo = null;
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $revokedAt = null;

	public function __construct()
	{
		$this->addType('managerUid', 'string');
		$this->addType('delegateUid', 'string');
		$this->addType('validFrom', 'datetime');
		$this->addType('validTo', 'datetime');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('revokedAt', 'datetime');
	}
}
