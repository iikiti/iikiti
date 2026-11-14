<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Challenge;

/**
 * Renders the self-contained interstitial. All dynamic values are passed through
 * attribute/JSON encoders so no request data can break out of the markup.
 */
final class FirewallChallengePageRenderer
{
	public function render(string $challenge, int $difficulty, string $reason, string $verifyPath): string
	{
		$config = json_encode([
			'challenge' => $challenge,
			'difficulty' => $difficulty,
			'verifyPath' => $verifyPath,
		], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

		$safeReason = htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Verifying your browser</title>
</head>
<body>
<main>
<h1>Verifying your browser</h1>
<p>{$safeReason}. This check takes a moment and only runs once.</p>
<noscript><p>JavaScript is required to continue.</p></noscript>
<form id="fw-challenge" method="post" action="{$verifyPath}" hidden>
<input type="hidden" name="challenge" id="fw-challenge-token">
<input type="hidden" name="solution" id="fw-challenge-solution">
</form>
</main>
<script>
(function () {
	var config = {$config};
	var encoder = new TextEncoder();
	function leadingZeroBits(bytes) {
		var bits = 0;
		for (var i = 0; i < bytes.length; i++) {
			var b = bytes[i];
			if (b === 0) { bits += 8; continue; }
			for (var mask = 0x80; mask && !(b & mask); mask >>= 1) { bits++; }
			break;
		}
		return bits;
	}
	async function solve() {
		for (var counter = 0; ; counter++) {
			var digest = new Uint8Array(await crypto.subtle.digest('SHA-256', encoder.encode(config.challenge + ':' + counter)));
			if (leadingZeroBits(digest) >= config.difficulty) {
				document.getElementById('fw-challenge-token').value = config.challenge;
				document.getElementById('fw-challenge-solution').value = String(counter);
				document.getElementById('fw-challenge').submit();
				return;
			}
			if (counter % 5000 === 0) { await new Promise(function (r) { setTimeout(r, 0); }); }
		}
	}
	solve();
})();
</script>
</body>
</html>
HTML;
	}
}
