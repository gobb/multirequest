<?php

/**
 * @see http://code.google.com/p/multirequest
 * @author Barbushin Sergey http://www.linkedin.com/in/barbushin
 *
 */
class MultiRequest_Handler {

	/**
	 * @var MultiRequest_RequestsDefaults
	 */
	protected $requestsDefaults;

	/**
	 * @var MultiRequest_Callbacks
	 */
	protected $callbacks;

	/**
	 * @var MultiRequest_Queue
	 */
	protected $queue;

	protected $connectionsLimit = 60;
	protected $totalTytesTransfered;
	protected $isActive;
	protected $isStarted;
	protected $isStopped;
	protected $activeRequests = array();
	protected $requestingDelay = 0;

	public function __construct() {
		$this->queue = new MultiRequest_Queue();
		$this->requestsDefaults = new MultiRequest_Defaults();
		$this->callbacks = new MultiRequest_Callbacks();
	}

	public function getQueue() {
		return $this->queue;
	}

	public function setRequestingDelay($milliseconds) {
		$this->requestingDelay = $milliseconds * 1000;
	}

	public function onRequestComplete($callback) {
		$this->callbacks->add(__FUNCTION__, $callback);
		return $this;
	}

	protected function notifyRequestComplete(MultiRequest_Request $request) {
		$request->notifyIsComplete($this);
		$this->callbacks->onRequestComplete($request, $this);
	}

	/**
	 * @return MultiRequest_Request
	 */
	public function requestsDefaults() {
		return $this->requestsDefaults;
	}

	public function isActive() {
		return $this->isActive;
	}

	public function isStarted() {
		return $this->isStarted;
	}

	public function setConnectionsLimit($connectionsCount) {
		$this->connectionsLimit = $connectionsCount;
	}

	public function getRequestsInQueueCount() {
		return $this->queue->count();
	}

	public function getActiveRequestsCount() {
		return count($this->activeRequests);
	}

	public function stop() {
		$this->isStopped = true;
	}

	public function activate() {
		$this->isStopped = false;
		$this->start();
	}

	public function pushRequestToQueue(MultiRequest_Request $request) {
		$this->queue->push($request);
	}

	protected function sendRequestToMultiCurl($mcurlHandle, MultiRequest_Request $request) {
		$this->requestsDefaults->applyToRequest($request);
		curl_multi_add_handle($mcurlHandle, $request->getCurlHandle());
	}

	public function start() {
		if($this->isActive || $this->isStopped) {
			return;
		}
		$this->isActive = true;
		$this->isStarted = true;

		try {

			$mcurlHandle = curl_multi_init();
			$mcurlStatus = null;
			$mcurlIsActive = false;

			do {

				if(count($this->activeRequests) < $this->connectionsLimit) {
					for($i = $this->connectionsLimit - count($this->activeRequests); $i > 0; $i --) {
						$request = $this->queue->pop();
						if($request) {
							$this->sendRequestToMultiCurl($mcurlHandle, $request);
							$this->activeRequests[$request->getId()] = $request;
						}
						else {
							break;
						}
					}
				}

				$mcurlStatus = curl_multi_exec($mcurlHandle, $mcurlIsActive);
				if($mcurlIsActive && curl_multi_select($mcurlHandle, 3) == -1) {
					throw new Exception('There are some errors in multi curl requests');
				}

				$completeCurlInfo = curl_multi_info_read($mcurlHandle);
				if($completeCurlInfo !== false) {
					$completeRequestId = MultiRequest_Request::getRequestIdByCurlHandle($completeCurlInfo['handle']);
					$completeRequest = $this->activeRequests[$completeRequestId];
					unset($this->activeRequests[$completeRequestId]);
					curl_multi_remove_handle($mcurlHandle, $completeRequest->getCurlHandle());
					$this->notifyRequestComplete($completeRequest);
					$mcurlIsActive = true;
				}
				else {
					usleep($this->requestingDelay);
				}
			}
			while(!$this->isStopped && ($mcurlStatus === CURLM_CALL_MULTI_PERFORM || $mcurlIsActive));
		}
		catch(Exception $exception) {
		}
		$this->isActive = false;
		if($mcurlHandle) {
			@curl_multi_close($mcurlHandle);
		}
		$this->callbacks->onComplete($this);

		if(!empty($exception)) {
			throw $exception;
		}
	}
}