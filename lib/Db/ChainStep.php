<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getChainId()
 * @method void setChainId(int $v)
 * @method int getStepNo()
 * @method void setStepNo(int $v)
 * @method string getStepType()
 * @method void setStepType(string $v)
 * @method ?string getGroupId()
 * @method void setGroupId(?string $v)
 * @method ?string getUserId()
 * @method void setUserId(?string $v)
 */
class ChainStep extends Entity
{
	protected int $chainId = 0;
	protected int $stepNo = 0;
	protected string $stepType = '';
	protected ?string $groupId = null;
	protected ?string $userId = null;

	public function __construct()
	{
		$this->addType('chainId', 'integer');
		$this->addType('stepNo', 'integer');
		$this->addType('stepType', 'string');
		$this->addType('groupId', 'string');
		$this->addType('userId', 'string');
	}
}
