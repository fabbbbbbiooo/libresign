<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023 Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\FileUserMapper;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Helper\JSActions;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Middleware\Attribute\RequireManager;
use OCA\Libresign\Middleware\Attribute\RequireSigner;
use OCA\Libresign\Service\FileService;
use OCA\Libresign\Service\SignFileService;
use OCA\TwoFactorGateway\Exception\SmsTransmissionException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SignFileController extends AEnvironmentAwareController {
	public function __construct(
		IRequest $request,
		protected IL10N $l10n,
		private FileUserMapper $fileUserMapper,
		private FileMapper $fileMapper,
		private IUserSession $userSession,
		private ValidateHelper $validateHelper,
		protected SignFileService $signFileService,
		private FileService $fileService,
		protected LoggerInterface $logger
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireManager]
	public function signUsingFileId(int $fileId, string $password = null, array $elements = [], string $code = null): JSONResponse {
		return $this->sign($password, $fileId, null, $elements, $code);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireSigner]
	public function signUsingUuid(string $uuid, string $password = null, array $elements = [], string $code = null): JSONResponse {
		return $this->sign($password, null, $uuid, $elements, $code);
	}

	public function sign(string $password = null, int $fileId = null, string $fileUserUuid = null, array $elements = [], string $code = null): JSONResponse {
		try {
			$user = $this->userSession->getUser();
			$this->validateHelper->canSignWithIdentificationDocumentStatus(
				$user,
				$this->fileService->getIdentificationDocumentsStatus($user->getUID())
			);
			$libreSignFile = $this->signFileService->getLibresignFile($fileId, $fileUserUuid);
			$fileUser = $this->signFileService->getFileUserToSign($libreSignFile, $user);
			$this->validateHelper->validateVisibleElementsRelation($elements, $fileUser, $user);
			$this->validateHelper->validateCredentials($fileUser, $user, [
				'password' => $password,
				'code' => $code,
			]);
			$this->signFileService
				->setLibreSignFile($libreSignFile)
				->setFileUser($fileUser)
				->storeUserMetadata([
					'user-agent' => $this->request->getHeader('User-Agent'),
					'remote-address' => $this->request->getRemoteAddress(),
				])
				->setVisibleElements($elements)
				->setSignWithoutPassword(!empty($code))
				->setPassword($password)
				->sign();

			return new JSONResponse(
				[
					'action' => JSActions::ACTION_SIGNED,
					'message' => $this->l10n->t('File signed'),
					'file' => [
						'uuid' => $libreSignFile->getUuid()
					]
				],
				Http::STATUS_OK
			);
		} catch (LibresignException $e) {
			return new JSONResponse(
				[
					'action' => JSActions::ACTION_DO_NOTHING,
					'errors' => [$e->getMessage()]
				],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (\Throwable $th) {
			$message = $th->getMessage();
			$action = JSActions::ACTION_DO_NOTHING;
			switch ($message) {
				case 'Password to sign not defined. Create a password to sign':
					$action = JSActions::ACTION_CREATE_SIGNATURE_PASSWORD;
					// no break
				case 'Host violates local access rules.':
				case 'Certificate Password Invalid.':
				case 'Certificate Password is Empty.':
					$message = $this->l10n->t($message);
					break;
				default:
					$this->logger->error($message);
					$this->logger->error(json_encode($th->getTrace()));
					$message = $this->l10n->t('Internal error. Contact admin.');
			}
		}
		return new JSONResponse(
			[
				'action' => $action,
				'errors' => [$message]
			],
			Http::STATUS_UNPROCESSABLE_ENTITY
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getCodeUsingUuid(string $uuid): JSONResponse {
		return $this->getCode($uuid);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireSigner]
	public function getCodeUsingFileId(int $fileId): JSONResponse {
		return $this->getCode(null, $fileId);
	}

	private function getCode(string $uuid = null, int $fileId = null): JSONResponse {
		$statusCode = null;
		try {
			try {
				$user = $this->userSession->getUser();
				if ($fileId) {
					$fileUser = $this->fileUserMapper->getByFileIdAndUserId($fileId, $user->getUID());
				} else {
					$fileUser = $this->fileUserMapper->getByUuidAndUserId($uuid, $user->getUID());
				}
			} catch (\Throwable $th) {
				throw new LibresignException($this->l10n->t('Invalid data to sign file'), 1);
			}
			$this->validateHelper->canRequestCode($fileUser);
			$libreSignFile = $this->fileMapper->getById($fileUser->getFileId());
			$this->validateHelper->fileCanBeSigned($libreSignFile);
			$this->signFileService->requestCode($fileUser, $user);
			$message = $this->l10n->t('The code to sign file was successfully requested.');
		} catch (SmsTransmissionException $e) {
			// There was an error when to send SMS code to user.
			$message = $this->l10n->t('Failed to send code.');
			$statusCode = Http::STATUS_UNPROCESSABLE_ENTITY;
		} catch (\Throwable $th) {
			$message = $th->getMessage();
			$statusCode = Http::STATUS_UNPROCESSABLE_ENTITY;
		}
		return new JSONResponse(
			[
				'message' => [$message],
			],
			$statusCode,
		);
	}
}
