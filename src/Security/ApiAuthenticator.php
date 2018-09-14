<?php
/**
 * Copyright (c) 2018 Heimrich & Hannot GmbH
 *
 * @author  Rico Kaltofen <r.kaltofen@heimrich-hannot.de>
 * @license http://www.gnu.org/licences/lgpl-3.0.html LGPL
 */

namespace HeimrichHannot\ApiBundle\Security;


use Contao\CoreBundle\Framework\ContaoFrameworkInterface;
use Contao\StringUtil;
use HeimrichHannot\ApiBundle\Exception\InvalidJWTException;
use HeimrichHannot\ApiBundle\Model\ApiAppModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Translation\TranslatorInterface;

class ApiAuthenticator extends GuardAuthenticator
{
    /**
     * Called on every request. Return whatever credentials you want to
     * be passed to getUser() as $credentials.
     */
    public function getCredentials(Request $request)
    {
        $this->translator->setLocale($request->getPreferredLanguage());

        if (!$request->headers->has('Authorization')) {
            throw new AuthenticationException($this->translator->trans('huh.api.exception.auth.missing_authorization_header'));
        }

        $headerParts = explode(' ', $request->headers->get('Authorization'));

        if (!(count($headerParts) === 2 && $headerParts[0] === 'Bearer')) {
            throw new AuthenticationException('huh.api.exception.auth.malformed_authorization_header');
        }

        if (!$request->query->get('key')) {
            throw new AuthenticationException('huh.api.exception.auth.missing_api_key.');
        }

        return [
            'token' => $headerParts[1],
            'key'   => $request->query->get('key'),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        try {
            $payload = $this->jwtCoder->decode($credentials['token']);
        } catch (InvalidJWTException $e) {
            throw new AuthenticationException($this->translator->trans($e->getMessage()));
        } catch (\Exception $e) {
            throw new AuthenticationException($this->translator->trans('huh.api.exception.auth.malformed_jwt'));
        }

        if (!isset($payload->username)) {
            throw new AuthenticationException('huh.api.exception.auth.invalid_jwt');
        }

        // if a User object, checkCredentials() is called
        return $userProvider->loadUserByUsername($payload->username);
    }

    /**
     * @inheritDoc
     */
    public function checkCredentials($credentials, UserInterface $user)
    {
        /** @var ApiAppModel $appModel */
        $appModel = $this->framework->createInstance(ApiAppModel::class);

        if (null === ($appModel = $appModel->findPublishedByKey($credentials['key']))) {
            throw new AuthenticationException($this->translator->trans('huh.api.exception.auth.invalid_api_key'));
        }

        if (empty($user->getRoles()) || empty(array_intersect(StringUtil::deserialize($appModel->groups, true), $user->getRoles()))) {
            throw new AuthenticationException($this->translator->trans('huh.api.exception.auth.user_not_allowed_for_api', ['%key%' => $credentials['key']]));
        }

        // if user object is present here, JWT token did already match
        return true;
    }
}