<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getClaimNo()
 * @method void setClaimNo(string $v)
 * @method string getUserId()
 * @method void setUserId(string $v)
 * @method string getTitle()
 * @method void setTitle(string $v)
 * @method ?string getPurpose()
 * @method void setPurpose(?string $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method string getCurrency()
 * @method void setCurrency(string $v)
 * @method int getTotalMinor()
 * @method void setTotalMinor(int $v)
 * @method int getReimbursableTotalMinor()
 * @method void setReimbursableTotalMinor(int $v)
 * @method int getNonreimbursableTotalMinor()
 * @method void setNonreimbursableTotalMinor(int $v)
 * @method int getOrgTotalMinor()
 * @method void setOrgTotalMinor(int $v)
 * @method string getFxRate()
 * @method void setFxRate(string $v)
 * @method ?int getChainId()
 * @method void setChainId(?int $v)
 * @method ?string getChainSnapshot()
 * @method void setChainSnapshot(?string $v)
 * @method int getCurrentStep()
 * @method void setCurrentStep(int $v)
 * @method ?int getCostCenterId()
 * @method void setCostCenterId(?int $v)
 * @method ?int getPcCustomerId()
 * @method void setPcCustomerId(?int $v)
 * @method ?int getPcProjectId()
 * @method void setPcProjectId(?int $v)
 * @method ?int getAzcTripId()
 * @method void setAzcTripId(?int $v)
 * @method ?int getPeriodId()
 * @method void setPeriodId(?int $v)
 * @method ?\DateTime getSubmittedAt()
 * @method void setSubmittedAt(?\DateTime $v)
 * @method ?\DateTime getDecidedAt()
 * @method void setDecidedAt(?\DateTime $v)
 * @method ?string getDecidedBy()
 * @method void setDecidedBy(?string $v)
 * @method ?string getDecisionNote()
 * @method void setDecisionNote(?string $v)
 * @method ?\DateTime getSettledAt()
 * @method void setSettledAt(?\DateTime $v)
 * @method ?string getSettledBy()
 * @method void setSettledBy(?string $v)
 * @method ?string getSettlementRef()
 * @method void setSettlementRef(?string $v)
 * @method ?int getBatchId()
 * @method void setBatchId(?int $v)
 * @method ?\DateTime getBilledAt()
 * @method void setBilledAt(?\DateTime $v)
 * @method ?string getBilledRef()
 * @method void setBilledRef(?string $v)
 * @method int getVersion()
 * @method void setVersion(int $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $v)
 * @method ?string getRequestKey()
 * @method void setRequestKey(?string $v)
 */
class Claim extends Entity
{
	protected string $claimNo = '';
	protected string $userId = '';
	protected string $title = '';
	protected ?string $purpose = null;
	protected string $status = '';
	protected string $currency = '';
	protected int $totalMinor = 0;
	protected int $reimbursableTotalMinor = 0;
	protected int $nonreimbursableTotalMinor = 0;
	protected int $orgTotalMinor = 0;
	protected string $fxRate = '';
	protected ?int $chainId = null;
	protected ?string $chainSnapshot = null;
	protected int $currentStep = 0;
	protected ?int $costCenterId = null;
	protected ?int $pcCustomerId = null;
	protected ?int $pcProjectId = null;
	protected ?int $azcTripId = null;
	protected ?int $periodId = null;
	protected ?\DateTime $submittedAt = null;
	protected ?\DateTime $decidedAt = null;
	protected ?string $decidedBy = null;
	protected ?string $decisionNote = null;
	protected ?\DateTime $settledAt = null;
	protected ?string $settledBy = null;
	protected ?string $settlementRef = null;
	protected ?int $batchId = null;
	protected ?\DateTime $billedAt = null;
	protected ?string $billedRef = null;
	protected int $version = 0;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;
	protected ?string $requestKey = null;

	public function __construct()
	{
		$this->addType('claimNo', 'string');
		$this->addType('userId', 'string');
		$this->addType('title', 'string');
		$this->addType('purpose', 'string');
		$this->addType('status', 'string');
		$this->addType('currency', 'string');
		$this->addType('totalMinor', 'integer');
		$this->addType('reimbursableTotalMinor', 'integer');
		$this->addType('nonreimbursableTotalMinor', 'integer');
		$this->addType('orgTotalMinor', 'integer');
		$this->addType('fxRate', 'string');
		$this->addType('chainId', 'integer');
		$this->addType('chainSnapshot', 'string');
		$this->addType('currentStep', 'integer');
		$this->addType('costCenterId', 'integer');
		$this->addType('pcCustomerId', 'integer');
		$this->addType('pcProjectId', 'integer');
		$this->addType('azcTripId', 'integer');
		$this->addType('periodId', 'integer');
		$this->addType('submittedAt', 'datetime');
		$this->addType('decidedAt', 'datetime');
		$this->addType('decidedBy', 'string');
		$this->addType('decisionNote', 'string');
		$this->addType('settledAt', 'datetime');
		$this->addType('settledBy', 'string');
		$this->addType('settlementRef', 'string');
		$this->addType('batchId', 'integer');
		$this->addType('billedAt', 'datetime');
		$this->addType('billedRef', 'string');
		$this->addType('version', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('requestKey', 'string');
	}
}
