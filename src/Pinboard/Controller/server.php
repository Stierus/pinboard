<?php

use Pinboard\Utils\Utils;

$server = $app['controllers_factory'];

$server->get('/{serverName}/{hostName}', function($serverName, $hostName) use ($app) {
    $result = array(
        'server_name' => $serverName,
        'hostname'    => $hostName,
        'title'       => $serverName,
    );
    
    $result['hosts']       = getHosts($app['db'], $serverName);    
    $result['statuses']    = getStatusesReview($app['db'], $serverName, $hostName);
    $result['req_per_sec'] = getRequestPerSecReview($app['db'], $serverName, $hostName);
    $result['req']         = getRequestReview($app['db'], $serverName, $hostName);

    return $app['twig']->render(
        'server.html.twig', 
        $result
    );
})
->value('hostName', 'all')
->bind('server');

function getHosts($conn, $serverName) {
    $sql = '
        SELECT
            DISTINCT hostname
        FROM
            ipm_report_by_hostname_and_server
        WHERE
            server_name = :server_name
        ORDER BY
            hostname
    ';

    $stmt = $conn->executeQuery($sql, array('server_name' => $serverName));
    $hosts = array();
    while ($data = $stmt->fetch()) {
      $hosts[] = $data['hostname'];
    }
    
    return $hosts;
}

function getStatusesReview($conn, $serverName, $hostName) {
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $hostCondition = '';
    
    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $hostCondition = 'AND hostname = :hostname';
    }
    
    $sql = '
        SELECT
            created_at, status, sum(req_count) as cnt
        FROM
            ipm_report_status
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
            AND status >= 500
        GROUP BY
            created_at
        ORDER BY
            created_at
    ';
    
    $stmt = $conn->executeQuery($sql, $params);
    
    $statuses = array(
        'data'  => array(),
        'codes' => array(),
    );
    while ($data = $stmt->fetch()) {
        $statuses['data'][date('Y,m,d,H,i', strtotime($data['created_at']))][$data['status'] > 0 ? $data['status'] : 'none'] = $data['cnt'];
        if (!isset($statuses['codes'][$data['status']])) {
            //set color
            $statuses['codes'][$data['status']] = Utils::generateColor();
        }
    }          
    ksort($statuses['codes']);

    return $statuses;            
}

function getRequestPerSecReview($conn, $serverName, $hostName) {
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $table = 'ipm_report_by_server_name';
    $hostCondition = '';
    
    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $table = 'ipm_report_by_hostname_and_server';
        $hostCondition = 'AND hostname = :hostname';
    }
    
    $sql = '
        SELECT
            created_at, req_per_sec
        FROM
            ' . $table . '
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
        ORDER BY
            created_at
    ';
    
    $data = $conn->fetchAll($sql, $params);
    
    foreach($data as &$item) {
        $item['date'] = date('Y,m,d,H,i', strtotime($item['created_at']));
        $item['req_per_sec'] = number_format($item['req_per_sec'], 2, '.', '');
    }

    return $data;
}

function getRequestReview($conn, $serverName, $hostName) {
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $hostCondition = '';
    $index = 'sn_c';
    
    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $hostCondition = 'AND hostname = :hostname';
        $index = 'sn_h_c';
    }
    
    $sql = '
        SELECT
            created_at, 
            req_time_90, req_time_95, req_time_99, req_time_100,
            mem_peak_usage_90, mem_peak_usage_95, mem_peak_usage_99, mem_peak_usage_100
        FROM
            ipm_report_2_by_hostname_and_server
        USE INDEX
            (' . $index . ')
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
        ORDER BY
            created_at
    ';
    
    $data = $conn->fetchAll($sql, $params);
    
    foreach($data as &$item) {
        $item['date'] = date('Y,m,d,H,i', strtotime($item['created_at']));
        $item['req_time_90']  = number_format($item['req_time_90'] * 1000, 0, '.', '');
        $item['req_time_95']  = number_format($item['req_time_95'] * 1000, 0, '.', '');
        $item['req_time_99']  = number_format($item['req_time_99'] * 1000, 0, '.', '');
        $item['req_time_100'] = number_format($item['req_time_100'] * 1000, 0, '.', '');
        $item['mem_peak_usage_90']  = number_format($item['mem_peak_usage_90'], 0, '.', '');
        $item['mem_peak_usage_95']  = number_format($item['mem_peak_usage_95'], 0, '.', '');
        $item['mem_peak_usage_99']  = number_format($item['mem_peak_usage_99'], 0, '.', '');
        $item['mem_peak_usage_100'] = number_format($item['mem_peak_usage_100'], 0, '.', '');
    }

    return $data;
}

$server->get('/{serverName}/{hostName}/statuses', function($serverName, $hostName) use ($app) {
    $result = array(
        'server_name' => $serverName,
        'hostname'    => $hostName,
        'title'       => 'Error pages / ' . $serverName,
    );
    
    $result['hosts']    = getHosts($app['db'], $serverName);    
    $result['statuses'] = getErrorPages($app['db'], $serverName, $hostName);

    return $app['twig']->render(
        'statuses.html.twig', 
        $result
    );
})
->value('hostName', 'all')
->bind('server_statuses');

function getErrorPages($conn, $serverName, $hostName) {
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $hostCondition = '';
    
    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $hostCondition = 'AND hostname = :hostname';
    }

    $sql = '
        SELECT
            DISTINCT server_name, hostname, script_name, status, created_at
        FROM
            ipm_status_details
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
        ORDER BY
            created_at DESC
        LIMIT
            100
    ';

    $data = $conn->fetchAll($sql, $params);
    
    return $data;
}

$server->get('/{serverName}/{hostName}/req-time', function($serverName, $hostName) use ($app) {
    $result = array(
        'server_name' => $serverName,
        'hostname'    => $hostName,
        'title'       => 'Request time / ' . $serverName,
    );
    
    $result['hosts'] = getHosts($app['db'], $serverName);    
    $result['pages'] = getSlowPages($app['db'], $serverName, $hostName);

    return $app['twig']->render(
        'req_time.html.twig', 
        $result
    );
})
->value('hostName', 'all')
->bind('server_req_time');

function getSlowPages($conn, $serverName, $hostName) {
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $hostCondition = '';
    
    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $hostCondition = 'AND hostname = :hostname';
    }

    $sql = '
        SELECT
            DISTINCT server_name, hostname, script_name, req_time, created_at
        FROM
            ipm_req_time_details
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
        ORDER BY
            created_at DESC, req_time DESC
        LIMIT
            100
    ';

    $data = $conn->fetchAll($sql, $params);
    
    foreach($data as &$item) {
        $item['req_time']  = number_format($item['req_time'] * 1000, 0, '.', ',');
    }
    
    return $data;
}

$server->get('/{serverName}/{hostName}/mem-usage', function($serverName, $hostName) use ($app) {
    $result = array(
        'server_name' => $serverName,
        'hostname'    => $hostName,
        'title'       => 'Memory peak usage / ' . $serverName,
    );
    
    $result['hosts'] = getHosts($app['db'], $serverName);    
    $result['pages'] = getHeavyPages($app['db'], $serverName, $hostName);

    return $app['twig']->render(
        'mem_usage.html.twig', 
        $result
    );
})
->value('hostName', 'all')
->bind('server_mem_usage');

function getHeavyPages($conn, $serverName, $hostName) {
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $hostCondition = '';
    
    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $hostCondition = 'AND hostname = :hostname';
    }

    $sql = '
        SELECT
            DISTINCT server_name, hostname, script_name, mem_peak_usage, created_at
        FROM
            ipm_mem_peak_usage_details
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
        ORDER BY
            created_at DESC, mem_peak_usage DESC
        LIMIT
            100
    ';

    $data = $conn->fetchAll($sql, $params);
    
    foreach($data as &$item) {
        $item['mem_peak_usage']  = number_format($item['mem_peak_usage'], 0, '.', ',');
    }
    
    return $data;
}

return $server;