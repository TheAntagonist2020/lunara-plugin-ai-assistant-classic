<?php
/**
 * Portable release contract for LUNARA AI Assistant Classic.
 * Run: php tests/release-contract.php
 */

$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . DIRECTORY_SEPARATOR . 'lunara-ai-assistant-classic.php' );
$readme = file_get_contents( $root . DIRECTORY_SEPARATOR . 'README.md' );
$failures = array();

if ( false === $plugin || false === $readme ) {
	fwrite( STDERR, "Could not read release files.\n" );
	exit( 1 );
}

$required = array(
	'Version:           0.6.1' => 'plugin header is not 0.6.1',
	"LUNARA_AI_ASSISTANT_CLASSIC_VERSION', '0.6.1'" => 'runtime version is not 0.6.1',
	"'x-goog-api-key' => \$api_key" => 'Gemini key is not sent in the request header',
	"':generateContent'" => 'Gemini Generate Content endpoint is missing',
);
foreach ( $required as $needle => $message ) {
	if ( false === strpos( $plugin, $needle ) ) {
		$failures[] = $message;
	}
}

if ( false !== strpos( $plugin, ':generateContent?key=' ) ) {
	$failures[] = 'Gemini key remains in the request URL';
}
if ( false === strpos( $readme, 'Current baseline: `0.6.1`.' ) ) {
	$failures[] = 'README baseline is not 0.6.1';
}

if ( $failures ) {
	fwrite( STDERR, "AI Assistant release contract failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "AI Assistant release contract passed.\n";
