<?php

namespace App\Processors\Security;

use App\Model\User;
use App\Model\UserOauth;
use Hybridauth\Adapter\OAuth2 as Provider;
use Hybridauth\User\Profile;

class Oauth2 extends \App\Processor
{
    /**
     * @return \Slim\Http\Response
     */
    public function get()
    {
        $provider = strtolower(@$this->getProperty('provider'));
        if (!in_array($provider, ['instagram', 'vkontakte'])) {
            return $this->failure([
                'error' => 'Неизвестный провайдер авторизации',
            ]);
        }

        $uri = $this->container->request->getUri();
        $params = [
            'provider' => $provider,
        ];
        if ($promo = (string)$this->getProperty('promo')) {
            $params['promo'] = $promo;
        }
        $config = [
            'callback' => $uri->getScheme() . '://' . $uri->getHost() . $uri->getPath() . '?' . http_build_query($params),
            'keys' => [
                'id' => getenv('OAUTH2_' . strtoupper($provider) . '_ID'),
                'secret' => getenv('OAUTH2_' . strtoupper($provider) . '_SECRET'),
            ],
        ];

        try {
            $class = '\Hybridauth\Provider\\' . ucfirst($provider);
            /** @var Provider $service */
            $service = new $class($config);
            $service->authenticate();
            /** @var Profile $profile */
            $profile = $service->getUserProfile();
            $service->disconnect();

            // Add profile to account
            if ($this->container->user) {
                if (!$oauth = $this->container->user->oauths()->where(['provider' => $provider])->first()) {
                    $oauth = new UserOauth([
                        'user_id' => $this->container->user->id,
                        'provider' => $provider,
                    ]);
                }
                $oauth->fill(json_decode(json_encode($profile), true));
                $oauth->save();

                if ($provider == 'instagram' && !$this->container->user->instagram) {
                    $this->container->user->instagram = array_pop(explode('/', $profile->profileURL));
                    $this->container->user->save();
                }

                return $this->success([
                    'success' => true,
                ]);
            }

            /** @var UserOauth $oauth */
            if ($oauth = UserOauth::query()->where(['provider' => $provider, 'identifier' => $profile->identifier])->first()) {
                $user = $oauth->user;
            } else {
                $oauth = new UserOauth([
                    'provider' => $provider,
                ]);
                if (!empty($profile->email) && $user = User::query()->where(['email' => $profile->email])->first()) {
                    // Link account to existing user
                    $oauth->user_id = $user->id;
                } else {
                    // Register new user
                    $processor = new Register($this->container);
                    $processor->setProperties([
                        'password' => bin2hex(openssl_random_pseudo_bytes(8)),
                        'email' => $profile->email,
                        'fullname' => !empty($profile->firstName) && !empty($profile->lastName)
                            ? $profile->firstName . ' ' . $profile->lastName
                            : $profile->displayName,
                        'instagram' => $provider == 'instagram'
                            ? array_pop(explode('/', $profile->profileURL))
                            : '',
                        'promo' => $promo,
                    ]);
                    $response = $processor->post();
                    if ($response->getStatusCode() !== 200) {
                        return $response;
                    }
                    $body = $response->getBody();
                    $body->rewind();
                    $user_id = json_decode($body->getContents())->id;
                    $user = User::query()->find($user_id);
                    $oauth->user_id = $user_id;
                }
                $oauth->fill(json_decode(json_encode($profile), true));
                $oauth->save();
            }

            if (!$user->active) {
                return $this->failure([
                    'error' => 'Авторизация невозможна - пользователь заблокирован',
                ]);
            }

            return $this->success([
                'token' => $this->container->makeToken($user->id),
            ]);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'denied')) {
                $message = 'Вы отказались давать доступ к своему профилю';
            }

            return $this->failure([
                'error' => $message,
            ]);
        }
    }


    /**
     * @return \Slim\Http\Response
     */
    public function delete()
    {
        $provider = strtolower(@$this->getProperty('provider'));
        if (!in_array($provider, ['instagram', 'vkontakte'])) {
            return $this->failure('Неизвестный провайдер авторизации');
        }

        if (!$this->container->user) {
            return $this->failure('Вы должны быть авторизованы для выполнения этой операции');
        }

        /** @var UserOauth $oauth */
        if ($oauth = $this->container->user->oauths()->where(['provider' => $provider])->first()) {
            try {
                $oauth->delete();
            } catch (\Throwable $e) {
                return $this->failure($e->getMessage());
            }
        }

        return $this->success();
    }
}