<?php

namespace SwooleTW\Http\Websocket;

use InvalidArgumentException;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;

trait Authenticatable
{
    /**
     * Login using current user.
     */
    public function loginUsing(AuthenticatableContract $user)
    {
        return $this->loginUsingId($user->getAuthIdentifier());
    }

    /**
     * Login using current userId.
     */
    public function loginUsingId($userId)
    {
        return $this->join(static::USER_PREFIX . $userId);
    }

    /**
     * Set multiple recepients' fds by users.
     */
    public function toUser($users)
    {
        $users = is_object($users) ? func_get_args() : $users;

        $userIds = array_map(function ($user) {
            $this->checkUser($user);
            return $user->getAuthIdentifier();
        }, $users);

        return $this->toUserId($userIds);
    }

    /**
     * Set multiple recepients' fds by userIds.
     */
    public function toUserId($userIds)
    {
        $userIds = is_string($userIds) || is_integer($userIds) ? func_get_args() : $userIds;

        foreach ($userIds as $userId) {
            $fds = $this->room->getClients(static::USER_PREFIX . $userId);
            $this->to($fds);
        }

        return $this;
    }

    /**
     * Check if user object implements AuthenticatableContract.
     */
    protected function checkUser($user)
    {
        if (! $user instanceOf AuthenticatableContract) {
            throw new InvalidArgumentException('user object must implement ' . AuthenticatableContract::class);
        }
    }
}
