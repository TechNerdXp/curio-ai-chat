<?php
/**
 * Provider contract.
 *
 * @package Curio
 */

namespace Curio\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * What every AI provider must be able to do.
 *
 * Kept narrow on purpose. Everything specific to one vendor — auth header
 * shape, request body, where the reply text hides in the response, what the
 * assistant role is called — is that adapter's business and nobody else's.
 * Adding a fourth provider should mean writing one file and adding one line to
 * the registry, and nothing else in the plugin should have to change.
 */
interface Provider_Interface {

	/**
	 * Machine name, used as the settings value and the key option suffix.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Human name for the settings screen.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Does this provider have what it needs to run?
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * The model used when the owner has not chosen one.
	 *
	 * @return string
	 */
	public function default_model(): string;

	/**
	 * Models to offer, live from the API when possible.
	 *
	 * @return array<string,string> Model id to label.
	 */
	public function models(): array;

	/**
	 * Ask the API for its current model list, bypassing any cache.
	 *
	 * @return array<string,string>|\WP_Error
	 */
	public function fetch_models();

	/**
	 * Answer a question.
	 *
	 * @param string                              $question Visitor's question.
	 * @param array<int,array<string,string>>     $history  Prior turns, oldest first.
	 * @param string                              $system   System prompt.
	 * @return array<string,mixed>|\WP_Error Keys: text, tokens_in, tokens_out.
	 */
	public function complete( string $question, array $history, string $system );

	/**
	 * Verify the stored credential works.
	 *
	 * @return true|\WP_Error
	 */
	public function test();

	/**
	 * Where the site owner goes to get a key, and where the legal text lives.
	 *
	 * The readme has to disclose every external service this plugin talks to,
	 * with links to its terms and privacy policy. Holding those URLs on the
	 * adapter means the disclosure and the settings screen cannot drift apart
	 * from the code that actually makes the call.
	 *
	 * @return array<string,string>
	 */
	public function links(): array;
}
