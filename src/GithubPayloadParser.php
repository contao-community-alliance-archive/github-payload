<?php

namespace ContaoCommunityAlliance\GithubPayload;

use ContaoCommunityAlliance\GithubPayload\Event\GithubEvent;
use ContaoCommunityAlliance\GithubPayload\Exception\BadSignatureException;
use JMS\Serializer\EventDispatcher\EventDispatcher;
use JMS\Serializer\EventDispatcher\Events;
use JMS\Serializer\EventDispatcher\PreDeserializeEvent;
use JMS\Serializer\EventDispatcher\PreSerializeEvent;
use JMS\Serializer\Serializer;
use JMS\Serializer\SerializerBuilder;
use Symfony\Component\HttpFoundation\Request;

class GithubPayloadParser
{
    /**
     * @var string
     */
    protected $secret;

    /**
     * @var Serializer
     */
    protected $serializer;

    /**
     * @return string
     */
    public function getSecret()
    {
        return $this->secret;
    }

    /**
     * @param string $secret
     *
     * @return static
     */
    public function setSecret($secret)
    {
        $this->secret = empty($secret) ? null : (string) $secret;
        return $this;
    }

    /**
     * @return Serializer
     */
    public function getSerializer()
    {
        if (!$this->serializer) {
            $builder = SerializerBuilder::create();
            $builder->configureListeners(
                function (EventDispatcher $eventDispatcher) {
                    $eventDispatcher->addListener(
                        Events::PRE_SERIALIZE,
                        function (PreSerializeEvent $event) {
                            /*
                             * Fixup issue 292, see https://github.com/schmittjoh/JMSSerializerBundle/issues/292
                             */
                            $object = $event->getObject();

                            if (is_object($object) && $object instanceof GithubEvent) {
                                $class = get_class($object);
                                $type  = $event->getType();

                                if ($class !== $type['name']) {
                                    $event->setType($class);
                                }
                            }
                        }
                    );

                    $eventDispatcher->addListener(
                        Events::PRE_DESERIALIZE,
                        function (PreDeserializeEvent $event) {
                            /*
                             * Fixup inconsistences in the same datetime field between events.
                             */
                            $type = $event->getType();

                            if ('DateTime' == $type['name'] && is_int($event->getData())) {
                                $date = new \DateTime();
                                $date->setTimezone(new \DateTimeZone($type['params'][1]));
                                $date->setTimestamp($event->getData());
                                $data = $date->format($type['params'][0]);
                                $event->setData($data);
                            }
                        }
                    );
                }
            );
            $this->serializer = $builder->build();
        }
        return $this->serializer;
    }

    /**
     * @param Serializer $serializer
     *
     * @return static
     */
    public function setSerializer(Serializer $serializer)
    {
        $this->serializer = $serializer;
        return $this;
    }

    /**
     * Parse the payload from plain php. Useful if you write a little entry script that use plain php.
     *
     * @return GithubEvent
     */
    public function parsePhp()
    {
        $headers = getallheaders();

        $eventName = $headers['X-Github-Event'];

        $payload = file_get_contents('php://input');

        if ($this->secret) {
            $signature = isset($headers['X-Hub-Signature']) ? $headers['X-Hub-Signature'] : null;
        } else {
            $signature = null;
        }

        return $this->parse($eventName, $payload, $signature);
    }

    /**
     * Parse the payload from a symfony request.
     *
     * @param Request $request
     *
     * @return GithubEvent
     */
    public function parseRequest($request)
    {
        if (!$request instanceof Request) {
            throw new \InvalidArgumentException(
                'The request must be an instance of Symfony\Component\HttpFoundation\Request'
            );
        }

        $eventName = $request->headers->get('X-Github-Event');
        $payload   = $request->getContent();
        $signature = $this->secret ? $request->headers->get('X-Hub-Signature') : null;

        return $this->parse($eventName, $payload, $signature);
    }

    /**
     * Parse a plain request body. This is the simplest way to parse the payload from a string.
     *
     * @param string      $eventName The event name, usually the X-Github-Event header.
     * @param string      $payload   The github payload, usually the POST body.
     * @param string|null $signature The payload signature, usually the X-Hub-Signature header.
     *
     * @return GithubEvent
     */
    public function parse($eventName, $payload, $signature = null)
    {
        if ($this->secret && !$signature) {
            throw new \InvalidArgumentException('Signature is required, but not given');
        }
        if (!$this->secret && $signature) {
            throw new \InvalidArgumentException('Signature is given, but no secret is specified');
        }

        if ($this->secret && $signature) {
            list($algorithm, $signatureHash) = explode('=', $signature, 2);
            $payloadHash = hash_hmac($algorithm, $payload, $this->secret);

            if ($signatureHash !== $payloadHash) {
                throw new BadSignatureException($payload, $signature, $signatureHash, $payloadHash);
            }
        }

        $serializer = $this->getSerializer();

        switch ($eventName) {
            case 'commit_comment':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\CommitCommentEvent';
                break;
            case 'create':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\CreateEvent';
                break;
            case 'delete':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\DeleteEvent';
                break;
            case 'deployment':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\DeploymentEvent';
                break;
            case 'deployment_status':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\DeploymentStatusEvent';
                break;
            case 'fork':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\ForkEvent';
                break;
            case 'gollum':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\GollumEvent';
                break;
            case 'issue_comment':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\IssueCommentEvent';
                break;
            case 'issues':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\IssuesEvent';
                break;
            case 'member':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\MemberEvent';
                break;
            case 'page_build':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\PageBuildEvent';
                break;
            case 'ping':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\PingEvent';
                break;
            case 'public':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\PublicEvent';
                break;
            case 'pull_request':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\PullRequestEvent';
                break;
            case 'pull_request_review_comment':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\PullRequestReviewCommentEvent';
                break;
            case 'push':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\PushEvent';
                break;
            case 'release':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\ReleaseEvent';
                break;
            case 'status':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\StatusEvent';
                break;
            case 'team_add':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\TeamAddEvent';
                break;
            case 'watch':
                $class = 'ContaoCommunityAlliance\GithubPayload\Event\WatchEvent';
                break;
            default:
                throw new \RuntimeException(
                    sprintf(
                        'Event %s is not supported',
                        $eventName
                    )
                );
        }

        $event = $serializer->deserialize($payload, $class, 'json');

        return $event;
    }
}
