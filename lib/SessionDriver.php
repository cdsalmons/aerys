<?php

namespace Aerys;

use Amp\Promise;

interface SessionDriver {
    /**
     * Creates a lock and reads the current session data
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function open(string $id): Promise;

    /**
     * Saves and unlocks a session
     * @param array $data to store
     * @return \Amp\Promise resolving after success
     */
    public function save(string $id, array $data): Promise;

    /**
     * Regenerates a session id
     * @return \Amp\Promise resolving after success
     */
    public function regenerate(string $oldId, string $newId): Promise {

    /**
     * Reloads the session contents and locks
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function read(string $id): Promise;

    /**
     * Unlocks the session, reloads data without saving
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function unlock(string $id): Promise;

    /**
     * Destroys the session
     * @return \Amp\Promise resolving after success
     */
    public function destroy(string $id): Promise;
}