<?php

namespace Tests\Feature;

use Tests\IsolatedAccountTestCase;

/**
 * Test that certain actions require a CSRF token.
 *
 * Since commit 821970fd, missing/invalid _token returns 200 JSON {"success":false,"message":"Invalid token."}
 * instead of a 500, to prevent an unhandled TypeError from hash_equals() surfacing as a production crash.
 */
class CsrfTest extends IsolatedAccountTestCase
{
    /**
     * Verify that issuing fastbuild requests without a CSRF token returns an "Invalid token" JSON error.
     */
    public function testFastBuildQueuesWithoutCsrfFail(): void
    {
        // Resources: Missing _token
        $response = $this->get('/resources/add-buildrequest?&type=1&planet_id=' . $this->planetService->getPlanetId());
        $response->assertStatus(200);
        $response->assertJson(['success' => false, 'message' => 'Invalid token.']);

        // Facilities: Missing _token
        $response = $this->get('/facilities/add-buildrequest?&type=14&planet_id=' . $this->planetService->getPlanetId());
        $response->assertStatus(200);
        $response->assertJson(['success' => false, 'message' => 'Invalid token.']);

        // Research: Missing _token
        $response = $this->get('/research/add-buildrequest?&type=113&planet_id=' . $this->planetService->getPlanetId());
        $response->assertStatus(200);
        $response->assertJson(['success' => false, 'message' => 'Invalid token.']);
    }
}
