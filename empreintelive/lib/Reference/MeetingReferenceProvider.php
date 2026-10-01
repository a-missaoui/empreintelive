<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Carte de reunion : un lien EMPREINTE colle dans Talk, Text, Deck, Collectives
 * ou un commentaire de fichier s'affiche sous forme de carte. Mecanisme de
 * references du coeur de Nextcloud : aucune dependance a Talk.
 *
 * Fournisseur « decouvrable » : il ajoute l'entree « EMPREINTE Live » au
 * selecteur de liens. Le selecteur ne l'affiche que si un element personnalise
 * est inscrit cote navigateur (src/reference.js) ; c'est cet element qui insere
 * le lien, toujours le lien participant.
 *
 * Droits : la carte est resolue avec le jeton EMPREINTE du lecteur, et le cache du
 * coeur est cloisonne par utilisateur. Le detail (titre, horaires) n'est montre
 * qu'au createur de la reunion : l'API renvoie une reunion a tout compte
 * authentifie, son succes ne vaut donc pas droit d'acces. Le lien organisateur ne
 * quitte jamais le serveur.
 */

namespace OCA\EmpreinteLive\Reference;

use OCA\EmpreinteLive\AppInfo\Application;
use OCA\EmpreinteLive\Service\EmpreinteLiveId;
use OCA\EmpreinteLive\Service\MeetingAccess;
use OCA\EmpreinteLive\Service\MeetingDomainService;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use function ctype_digit;
use function is_string;
use function parse_str;
use function parse_url;
use function preg_replace;
use function rtrim;
use function strtolower;

class MeetingReferenceProvider extends ADiscoverableReferenceProvider {
	/** Doit rester identique a RICH_OBJECT_TYPE de src/reference.js. */
	public const RICH_OBJECT_TYPE = 'empreintelive_meeting';
	/** Doit rester identique a PICKER_ID de src/reference.js. */
	public const PICKER_ID = 'empreintelive';

	public function __construct(
		private MeetingDomainService $domains,
		private MeetingAccess $access,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
	) {
	}

	public function getId(): string {
		return self::PICKER_ID;
	}

	public function getTitle(): string {
		return $this->l10n->t('EMPREINTE Live');
	}

	public function getOrder(): int {
		return 20;
	}

	public function getIconUrl(): string {
		// Variante sombre : l'entree s'affiche sur fond clair.
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'),
		);
	}

	public function matchReference(string $referenceText): bool {
		return $this->liveId($referenceText) !== null;
	}

	public function resolveReference(string $referenceText): ?IReference {
		$liveId = $this->liveId($referenceText);
		if ($liveId === null) {
			return null;
		}

		$reference = new Reference($referenceText);
		$reference->setUrl($referenceText);
		$userId = $this->userSession->getUser()?->getUID();
		$meeting = $userId !== null ? $this->access->ownMeeting($userId, $liveId) : null;

		if ($meeting === null) {
			// Carte minimale : aucun detail pour un lecteur qui n'est pas le createur.
			$reference->setTitle($this->l10n->t('EMPREINTE Live meeting'));
			$reference->setRichObject(self::RICH_OBJECT_TYPE, [
				'liveId' => $liveId,
				'detailed' => false,
				'joinUrl' => $referenceText,
			]);
			return $reference;
		}

		$title = (string)($meeting['title'] ?? '');
		$reference->setTitle($title !== '' ? $title : $this->l10n->t('EMPREINTE Live meeting'));
		$reference->setRichObject(self::RICH_OBJECT_TYPE, [
			'liveId' => $liveId,
			'detailed' => true,
			'title' => $title,
			'start' => $meeting['dateStartDiffusion'] ?? null,
			'end' => $meeting['dateEndDiffusion'] ?? null,
			// Le createur rejoint depuis la page de l'app : reunion embarquee et
			// panneau documentaire.
			'joinUrl' => $this->appPageUrl($liveId),
			'organizerLinkShared' => $this->sameToken($referenceText, (string)($meeting['admin_url'] ?? '')),
		]);
		return $reference;
	}

	public function getCachePrefix(string $referenceId): string {
		return (string)$this->liveId($referenceId);
	}

	public function getCacheKey(string $referenceId): ?string {
		// Une entree par lecteur ET par lien : la carte depend des droits du lecteur,
		// et deux liens d'une meme reunion (participant, organisateur) ne donnent pas
		// la meme carte. Le coeur ne tient compte que du prefixe et de cette cle.
		// Le prefixe reste le liveId : invalidateCache($liveId) vide toutes les
		// cartes de la reunion.
		return ($this->userSession->getUser()?->getUID() ?? '') . "\n" . $referenceId;
	}

	/**
	 * liveId d'un lien de reunion EMPREINTE ou d'un lien vers la page de l'app,
	 * null pour tout autre lien. Le domaine est verifie avant l'extraction :
	 * EmpreinteLiveId::fromUrl() accepte un parametre room= sur n'importe quel hote.
	 */
	private function liveId(string $url): ?string {
		$parts = parse_url($url);
		if (!isset($parts['scheme'], $parts['host'])) {
			return null;
		}
		$origin = strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));

		foreach ($this->domains->domains() as $domain) {
			if (strtolower(rtrim($domain, '/')) === $origin) {
				return EmpreinteLiveId::fromUrl($url);
			}
		}

		return $this->liveIdOfAppPage($url, $parts);
	}

	/**
	 * @param array<string,mixed> $parts
	 */
	private function liveIdOfAppPage(string $url, array $parts): ?string {
		$page = parse_url($this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index'));
		if (!isset($page['host']) || strtolower((string)$page['host']) !== strtolower((string)$parts['host'])) {
			return null;
		}
		if ($this->routePath((string)($page['path'] ?? '')) !== $this->routePath((string)($parts['path'] ?? ''))) {
			return null;
		}
		parse_str((string)($parts['query'] ?? ''), $query);
		$live = $query['live'] ?? null;
		return is_string($live) && ctype_digit($live) ? $live : null;
	}

	/**
	 * Chemin de route comparable : avec ou sans « /index.php » (URL propres
	 * activees ou non), avec ou sans barre finale.
	 */
	private function routePath(string $path): string {
		return rtrim(preg_replace('#/index\.php(?=/|$)#', '', $path) ?? $path, '/');
	}

	private function appPageUrl(string $liveId): string {
		return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index', ['live' => $liveId]);
	}

	/**
	 * Vrai si le lien colle porte le meme jeton que le lien organisateur : les
	 * deux liens ne different que par ce jeton.
	 */
	private function sameToken(string $pasted, string $adminUrl): bool {
		$a = $this->tokenOf($pasted);
		return $a !== '' && $a === $this->tokenOf($adminUrl);
	}

	private function tokenOf(string $url): string {
		parse_str((string)(parse_url($url, PHP_URL_QUERY) ?? ''), $query);
		$token = $query['token'] ?? '';
		return is_string($token) ? $token : '';
	}
}
