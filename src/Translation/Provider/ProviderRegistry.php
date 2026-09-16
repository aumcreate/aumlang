<?php
/**
 * Registry of available translation providers.
 *
 * @package AumLang
 */

namespace AumLang\Translation\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Holds registered providers and tracks which one is active.
 */
class ProviderRegistry {

	/**
	 * Registered providers, keyed by id.
	 *
	 * @var array<string, ProviderInterface>
	 */
	private $providers = array();

	/**
	 * Active provider id.
	 *
	 * @var string
	 */
	private $active = '';

	/**
	 * Register a provider (replaces any existing one with the same id).
	 *
	 * @param ProviderInterface $provider Provider to register.
	 * @return void
	 */
	public function register( ProviderInterface $provider ) {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Get a provider by id.
	 *
	 * @param string $id Provider id.
	 * @return ProviderInterface|null
	 */
	public function get( $id ) {
		return isset( $this->providers[ $id ] ) ? $this->providers[ $id ] : null;
	}

	/**
	 * All registered providers.
	 *
	 * @return array<string, ProviderInterface>
	 */
	public function all() {
		return $this->providers;
	}

	/**
	 * Set the active provider id.
	 *
	 * @param string $id Provider id.
	 * @return void
	 */
	public function set_active( $id ) {
		$this->active = (string) $id;
	}

	/**
	 * Get the active provider, if it is registered.
	 *
	 * @return ProviderInterface|null
	 */
	public function get_active() {
		return $this->get( $this->active );
	}
}
