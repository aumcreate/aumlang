<?php
/**
 * Lightweight dependency injection container.
 *
 * @package AumLang
 */

namespace AumLang\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Stores service factories and resolves them lazily as shared singletons.
 */
class Container {

	/**
	 * Resolved service instances, keyed by service id.
	 *
	 * @var array<string, mixed>
	 */
	private $instances = array();

	/**
	 * Service factories, keyed by service id.
	 *
	 * @var array<string, callable>
	 */
	private $factories = array();

	/**
	 * Register a service factory.
	 *
	 * @param string   $id      Service identifier.
	 * @param callable $factory Receives the container, returns the service.
	 * @return void
	 */
	public function set( $id, callable $factory ) {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Resolve a service, building it once on first access.
	 *
	 * @param string $id Service identifier.
	 * @return mixed
	 * @throws \InvalidArgumentException When the service is not registered.
	 */
	public function get( $id ) {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'AumLang container: unknown service "%s".', esc_html( $id ) )
			);
		}

		$this->instances[ $id ] = call_user_func( $this->factories[ $id ], $this );

		return $this->instances[ $id ];
	}

	/**
	 * Whether a service id is known to the container.
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( $id ) {
		return isset( $this->factories[ $id ] ) || array_key_exists( $id, $this->instances );
	}
}
