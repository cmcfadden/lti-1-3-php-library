<?php
namespace LTI;

class LtiNamesRolesProvisioningService {

    private $service_connector;
    private $service_data;

    public function __construct(LtiServiceConnector $service_connector, $service_data) {
        $this->service_connector = $service_connector;
        $this->service_data = $service_data;
    }

    public function getMembers() {

        $members = [];

        $next_page = $this->service_data['context_memberships_url'];

        while ($next_page) {
            $page = $this->service_connector->makeServiceRequest(
                [LtiConstants::NRPS_CONTEXT_MEMBERSHIP_READ_ONLY],
                'GET',
                $next_page,
                null,
                null,
                'application/vnd.ims.lti-nrps.v2.membershipcontainer+json'
            );

            $members = array_merge($members, $page['body']['members']);

            $next_page = false;
            foreach($page['headers'] as $header) {
                if (preg_match(LtiServiceConnector::NEXT_PAGE_REGEX, $header, $matches)) {
                    $next_page = $matches[1];
                    break;
                }
            }
        }
        return $members;

    }
}