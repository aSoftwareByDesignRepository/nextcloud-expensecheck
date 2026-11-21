<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Controller;

use OCA\ExpenseCheck\Controller\OnboardingApiController;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * §9.3 first-run checklist: dismiss flag is per-user (IConfig user scope),
 * default is not-dismissed, dismiss persists '1'.
 */
class OnboardingApiControllerTest extends TestCase {
	private IConfig $config;
	private OnboardingApiController $ctrl;

	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('u9');
		$this->config = $this->createMock(IConfig::class);
		$this->ctrl = new OnboardingApiController($request, $access, $this->config);
	}

	public function testDefaultNotDismissed(): void {
		$this->config->method('getUserValue')
			->with('u9', 'expensecheck', 'onboarding_dismissed', '0')
			->willReturn('0');
		$res = $this->ctrl->show()->getData();
		$this->assertFalse($res['dismissed']);
	}

	public function testDismissedReadsOne(): void {
		$this->config->method('getUserValue')->willReturn('1');
		$this->assertTrue($this->ctrl->show()->getData()['dismissed']);
	}

	public function testDismissWritesUserScope(): void {
		$this->config->expects($this->once())
			->method('setUserValue')
			->with('u9', 'expensecheck', 'onboarding_dismissed', '1');
		$this->assertTrue($this->ctrl->dismiss()->getData()['dismissed']);
	}
}
