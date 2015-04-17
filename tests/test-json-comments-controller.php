<?php

/**
 * Unit tests covering WP_JSON_Comments_Controller functionality.
 *
 * @package WordPress
 * @subpackage JSON API
 */
class WP_Test_JSON_Comments_Controller extends WP_Test_JSON_Controller_Testcase {

	protected $admin_id;
	protected $subscriber_id;

	protected $post_id;

	protected $approved_id;
	protected $hold_id;

	protected $endpoint;

	public function setUp() {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array(
			'role' => 'administrator',
		));
		$this->subscriber_id = $this->factory->user->create( array(
			'role' => 'subscriber',
		));

		$this->post_id = $this->factory->post->create();

		$this->approved_id = $this->factory->comment->create( array(
			'comment_approved' => 1,
			'comment_post_ID'  => $this->post_id,
			'user_id'          => 0,
		));
		$this->hold_id = $this->factory->comment->create( array(
			'comment_approved' => 0,
			'comment_post_ID'  => $this->post_id,
			'user_id'          => $this->subscriber_id,
		));

		$this->endpoint = new WP_JSON_Comments_Controller;
	}

	public function tearDown() {
		parent::tearDown();
	}

	public function test_register_routes() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wp/comments', $routes );
		$this->assertCount( 2, $routes['/wp/comments'] );
		$this->assertArrayHasKey( '/wp/comments/(?P<id>[\d]+)', $routes );
		$this->assertCount( 3, $routes['/wp/comments/(?P<id>[\d]+)'] );
	}

	public function test_get_items() {
		$this->factory->comment->create_post_comments( $this->post_id, 6 );

		$request = new WP_JSON_Request( 'GET', '/wp/comments' );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$comments = $response->get_data();
		// We created 6 comments in this method, plus $this->approved_id.
		$this->assertCount( 7, $comments );
	}

	public function test_get_items_for_post() {
		$second_post_id = $this->factory->post->create();
		$this->factory->comment->create_post_comments( $second_post_id, 2 );

		$request = new WP_JSON_Request( 'GET', '/wp/comments' );
		$request->set_query_params( array(
			'post' => $second_post_id,
		) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$comments = $response->get_data();
		$this->assertCount( 2, $comments );
	}

	public function test_get_item() {
		$request = new WP_JSON_Request( 'GET', sprintf( '/wp/comments/%d', $this->approved_id ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->check_comment_data( $data, 'view' );
	}

	public function test_prepare_item() {
		wp_set_current_user( $this->admin_id );
		$request = new WP_JSON_Request( 'GET', sprintf( '/wp/comments/%d', $this->approved_id ) );
		$request->set_query_params( array(
			'context' => 'edit',
		) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->check_comment_data( $data, 'edit' );
	}

	public function test_get_comment_invalid_id() {
		$request = new WP_JSON_Request( 'GET', '/wp/comments/' . 100 );

		$response = $this->server->dispatch( $request );
		$this->assertErrorResponse( 'json_comment_invalid_id', $response, 404 );
	}

	public function test_get_comment_invalid_context() {
		$request = new WP_JSON_Request( 'GET', sprintf( '/wp/comments/%s', $this->approved_id ) );
		$request->set_param( 'context', 'edit' );
		$response = $this->server->dispatch( $request );
		$this->assertErrorResponse( 'json_forbidden', $response, 403 );
	}

	public function test_get_comment_invalid_post_id() {
		$comment_id = $this->factory->comment->create( array(
			'comment_approved' => 1,
			'comment_post_ID'  => 100,
		));
		$request = new WP_JSON_Request( 'GET', '/wp/comments/' . $comment_id );

		$response = $this->server->dispatch( $request );
		$this->assertErrorResponse( 'json_post_invalid_id', $response, 404 );
	}

	public function test_get_comment_not_approved() {
		wp_set_current_user( 0 );

		$request = new WP_JSON_Request( 'GET', sprintf( '/wp/comments/%d', $this->hold_id ) );

		$response = $this->server->dispatch( $request );
		$this->assertErrorResponse( 'json_forbidden', $response, 403 );
	}

	public function test_get_comment_not_approved_same_user() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_JSON_Request( 'GET', sprintf( '/wp/comments/%d', $this->hold_id ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
	}

	public function test_create_item() {
		wp_set_current_user( 0 );

		$params = array(
			'post'    => $this->post_id,
			'author'  => array(
				'name'  => 'Comic Book Guy',
				'email' => 'cbg@androidsdungeon.com',
				'url'   => 'http://androidsdungeon.com',
			),
			'content' => 'Worst Comment Ever!',
			'date'    => '2014-11-07T10:14:25',
		);

		$request = new WP_JSON_Request( 'POST', '/wp/comments' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( json_encode( $params ) );

		$response = $this->server->dispatch( $request );
		$response = json_ensure_response( $response );
		$this->assertEquals( 201, $response->get_status() );

		$data = $response->get_data();
		$this->check_comment_data( $data, 'view' );
		$this->assertEquals( 'hold', $data['status'] );
		$this->assertEquals( '2014-11-07T10:14:25', $data['date'] );
	}

	public function test_create_comment_other_user() {
		wp_set_current_user( $this->admin_id );

		$params = array(
			'post'    => $this->post_id,
			'author'  => array(
				'name'  => 'Homer Jay Simpson',
				'email' => 'chunkylover53@aol.com',
				'url'   => 'http://compuglobalhypermeganet.com',
			),
			'content' => 'Here’s to alcohol: the cause of, and solution to, all of life’s problems.',
			'user'    => 0,
		);

		$request = new WP_JSON_Request( 'POST', '/wp/comments' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( json_encode( $params ) );
		$response = $this->server->dispatch( $request );

		$response = json_ensure_response( $response );
		$this->assertEquals( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertInternalType( 'array', $data['author'] );
		$this->assertEquals( 0, $data['author']['id'] );
	}

	public function test_create_item_duplicate() {
		$this->markTestSkipped( 'Needs to be revisited after wp_die handling is added' );
		$original_id = $this->factory->comment->create(
			array(
				'comment_post_ID'      => $this->post_id,
				'comment_author'       => 'Guy N. Cognito',
				'comment_author_email' => 'chunkylover53@aol.co.uk',
				'comment_content'      => 'Homer? Who is Homer? My name is Guy N. Cognito.',
			)
		);
		wp_set_current_user( 0 );

		$params = array(
			'post'    => $this->post_id,
			'author'  => array(
				'name'  => 'Guy N. Cognito',
				'email' => 'chunkylover53@aol.co.uk',
			),
			'content' => 'Homer? Who is Homer? My name is Guy N. Cognito.',
		);

		$request = new WP_JSON_Request( 'POST', '/wp/comments' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( json_encode( $params ) );
		$response = $this->server->dispatch( $request );

		$response = json_ensure_response( $response );
		$this->assertEquals( 409, $response->get_status() );
	}

	public function test_create_comment_closed() {
		$post_id = $this->factory->post->create( array(
			'comment_status' => 'closed',
		));
		wp_set_current_user( 0 );

		$params = array(
			'post'      => $post_id,
		);

		$request = new WP_JSON_Request( 'POST', '/wp/comments' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( json_encode( $params ) );
		$response = $this->server->dispatch( $request );

		$response = json_ensure_response( $response );
		$this->assertEquals( 403, $response->get_status() );
	}

	public function test_update_item() {
		wp_set_current_user( $this->admin_id );

		$params = array(
			'content' => "Disco Stu doesn't advertise.",
			'author'  => array(
				'name'  => 'Disco Stu',
				'url'   => 'http://stusdisco.com',
				'email' => 'stu@stusdisco.com',
			),
			'date'    => '2014-11-07T10:14:25',
		);
		$request = new WP_JSON_Request( 'PUT', sprintf( '/wp/comments/%d', $this->approved_id ) );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( json_encode( $params ) );

		$response = $this->server->dispatch( $request );
		$response = json_ensure_response( $response );
		$this->assertEquals( 201, $response->get_status() );

		$comment = $response->get_data();
		$updated = get_comment( $this->approved_id );
		$this->assertEquals( $params['content'], $comment['content']['raw'] );
		$this->assertInternalType( 'array', $comment['author'] );
		$this->assertEquals( $params['author']['name'], $comment['author']['name'] );
		$this->assertEquals( $params['author']['url'], $comment['author']['url'] );
		$this->assertEquals( $params['author']['email'], $comment['author']['email'] );

		$this->assertEquals( json_mysql_to_rfc3339( $updated->comment_date ), $comment['date'] );
		$this->assertEquals( '2014-11-07T10:14:25', $comment['date'] );
	}

	public function test_update_comment_status() {
		wp_set_current_user( $this->admin_id );

		$comment_id = $this->factory->comment->create( array(
			'comment_approved' => 0,
			'comment_post_ID'  => $this->post_id,
		));

		$params = array(
			'status' => 'approve',
		);
		$request = new WP_JSON_Request( 'PUT', sprintf( '/wp/comments/%d', $comment_id ) );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( json_encode( $params ) );

		$response = $this->server->dispatch( $request );
		$response = json_ensure_response( $response );
		$this->assertEquals( 201, $response->get_status() );

		$comment = $response->get_data();
		$updated = get_comment( $comment_id );
		$this->assertEquals( 'approved', $comment['status'] );
		$this->assertEquals( 1, $updated->comment_approved );
	}

	public function test_update_comment_invalid_id() {
		wp_set_current_user( 0 );

		$params = array(
			'content' => 'Oh, they have the internet on computers now!',
		);
		$request = new WP_JSON_Request( 'PUT', '/wp/comments/' . 100 );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( json_encode( $params ) );

		$response = $this->server->dispatch( $request );
		$this->assertErrorResponse( 'json_comment_invalid_id', $response, 404 );
	}

	public function test_update_comment_invalid_permission() {
		wp_set_current_user( 0 );

		$params = array(
			'content' => 'Disco Stu likes disco music.',
		);
		$request = new WP_JSON_Request( 'PUT', sprintf( '/wp/comments/%d', $this->hold_id ) );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( json_encode( $params ) );

		$response = $this->server->dispatch( $request );
		$this->assertErrorResponse( 'json_forbidden', $response, 403 );
	}

	public function test_delete_item() {
		wp_set_current_user( $this->admin_id );

		$comment_id = $this->factory->comment->create( array(
			'comment_approved' => 1,
			'comment_post_ID'  => $this->post_id,
			'user_id'          => $this->subscriber_id,
		));
		$request = new WP_JSON_Request( 'DELETE', sprintf( '/wp/comments/%d', $comment_id ) );

		$response = $this->server->dispatch( $request );
		$response = json_ensure_response( $response );
		$this->assertEquals( 200, $response->get_status() );
	}

	public function test_delete_comment_invalid_id() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_JSON_Request( 'DELETE', sprintf( '/wp/comments/%d', 100 ) );

		$response = $this->server->dispatch( $request );
		$response = json_ensure_response( $response );
		$this->assertErrorResponse( 'json_comment_invalid_id', $response, 404 );
	}

	public function test_delete_comment_without_permission() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_JSON_Request( 'DELETE', sprintf( '/wp/comments/%d', $this->approved_id ) );

		$response = $this->server->dispatch( $request );
		$this->assertErrorResponse( 'json_forbidden', $response, 403 );
	}

	public function test_get_item_schema() {
		$request = new WP_JSON_Request( 'GET', '/wp/comments/schema' );
		$response = $this->server->dispatch( $request );
		$data = $response->get_data();
		$properties = $data['properties'];
		$this->assertEquals( 12, count( $properties ) );
		$this->assertArrayHasKey( 'id', $properties );
		$this->assertArrayHasKey( 'author', $properties );
		$this->assertArrayHasKey( 'author_email', $properties );
		$this->assertArrayHasKey( 'author_url', $properties );
		$this->assertArrayHasKey( 'content', $properties );
		$this->assertArrayHasKey( 'date', $properties );
		$this->assertArrayHasKey( 'link', $properties );
		$this->assertArrayHasKey( 'parent', $properties );
		$this->assertArrayHasKey( 'post', $properties );
		$this->assertArrayHasKey( 'status', $properties );
		$this->assertArrayHasKey( 'type', $properties );
		$this->assertArrayHasKey( 'user', $properties );
	}

	protected function check_comment_data( $data, $context ) {
		$comment = get_comment( $data['id'] );

		$this->assertEquals( $comment->comment_ID, $data['id'] );
		$this->assertEquals( $comment->comment_post_ID, $data['post'] );
		$this->assertEquals( $comment->comment_parent, $data['parent'] );
		$this->assertInternalType( 'array', $data['author'] );
		$this->assertEquals( $comment->user_id, $data['author']['id' ] );
		$this->assertEquals( $comment->comment_author, $data['author']['name'] );
		$this->assertEquals( $comment->comment_author_url, $data['author']['url'] );
		$this->assertEquals( wpautop( $comment->comment_content ), $data['content']['rendered'] );
		$this->assertEquals( json_mysql_to_rfc3339( $comment->comment_date ), $data['date'] );
		$this->assertEquals( get_comment_link( $comment ), $data['link'] );

		if ( 'edit' === $context ) {
			$this->assertEquals( $comment->comment_author_email, $data['author']['email'] );
			$this->assertEquals( $comment->comment_author_IP, $data['author']['ip'] );
			$this->assertEquals( $comment->comment_agent, $data['author']['user_agent'] );
			$this->assertEquals( json_mysql_to_rfc3339( $comment->comment_date_gmt ), $data['date_gmt'] );
			$this->assertEquals( $comment->comment_content, $data['content']['raw'] );
			$this->assertEquals( $comment->comment_karma, $data['karma'] );
		}

		if ( 'edit' !== $context ) {
			$this->assertFalse( $data['author']['email'] );
			$this->assertArrayNotHasKey( 'ip', $data['author'] );
			$this->assertArrayNotHasKey( 'user_agent', $data['author'] );
			$this->assertArrayNotHasKey( 'date_gmt', $data );
			$this->assertArrayNotHasKey( 'raw', $data['content'] );
			$this->assertArrayNotHasKey( 'karma', $data );
		}
	}


}
