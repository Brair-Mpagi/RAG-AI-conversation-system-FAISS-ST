<?php
ini_set('display_errors',1); error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: ./admin-login.php"); exit(); }
require_once 'db.php';

function fd($conn,$q,...$p){$s=$conn->prepare($q);if($p){$t=str_repeat('s',count($p));$s->bind_param($t,...$p);}$s->execute();$r=$s->get_result();return $r?$r->fetch_all(MYSQLI_ASSOC):[];}

$admin=fd($conn,"SELECT admin_id,username,email,full_name,role FROM admins WHERE admin_id=?",$_SESSION['admin_id'])[0]??[];

$dr=isset($_GET['date_range'])?$_GET['date_range']:'30';
$from=isset($_GET['from'])?$_GET['from']:'';
$to=isset($_GET['to'])?$_GET['to']:'';
if($dr==='custom'&&$from&&$to){
    $from=mysqli_real_escape_string($conn,$from);$to=mysqli_real_escape_string($conn,$to);
    $dsql="created_at BETWEEN '$from 00:00:00' AND '$to 23:59:59'";
    $dsql_sub="submitted_at BETWEEN '$from 00:00:00' AND '$to 23:59:59'";
    $dsql_time="start_time BETWEEN '$from 00:00:00' AND '$to 23:59:59'";
}else{
    $days=in_array($dr,['7','30','60'])?(int)$dr:30;
    $dsql="created_at >= DATE_SUB(NOW(),INTERVAL $days DAY)";
    $dsql_sub="submitted_at >= DATE_SUB(NOW(),INTERVAL $days DAY)";
    $dsql_time="start_time >= DATE_SUB(NOW(),INTERVAL $days DAY)";
}
$prev_dsql="created_at BETWEEN DATE_SUB(NOW(),INTERVAL 60 DAY) AND DATE_SUB(NOW(),INTERVAL 30 DAY)";

// ── CHAT METRICS ──
$total_queries=count(fd($conn,"SELECT message_id FROM chat_messages WHERE $dsql"));
$total_sessions=count(fd($conn,"SELECT DISTINCT session_id FROM chat_messages WHERE $dsql"));
$prev_queries=count(fd($conn,"SELECT message_id FROM chat_messages WHERE $prev_dsql"));
$prev_sessions=count(fd($conn,"SELECT DISTINCT session_id FROM chat_messages WHERE $prev_dsql"));
$avg_resp=fd($conn,"SELECT AVG(response_time_ms) v FROM chat_messages WHERE $dsql")[0]['v']??0;
$avg_conf=fd($conn,"SELECT AVG(confidence_score) v FROM chat_messages WHERE $dsql")[0]['v']??0;
$ctx_used=fd($conn,"SELECT COUNT(*) v FROM chat_messages WHERE context_retrieved=1 AND $dsql")[0]['v']??0;

$daily=fd($conn,"SELECT DATE(created_at) dt,COUNT(*) cnt FROM chat_messages WHERE $dsql GROUP BY DATE(created_at) ORDER BY dt");
$hourly=fd($conn,"SELECT HOUR(created_at) hr,COUNT(*) cnt FROM chat_messages WHERE $dsql GROUP BY HOUR(created_at) ORDER BY hr");
$intents=fd($conn,"SELECT COALESCE(intent_classification,'unclassified') nm,COUNT(*) cnt FROM chat_messages WHERE $dsql GROUP BY intent_classification ORDER BY cnt DESC LIMIT 8");
$resp_types=fd($conn,"SELECT COALESCE(response_type,'unknown') rt,COUNT(*) cnt FROM chat_messages WHERE $dsql GROUP BY response_type ORDER BY cnt DESC");
$models_used=fd($conn,"SELECT COALESCE(model_used,'N/A') m,COUNT(*) cnt FROM chat_messages WHERE $dsql AND model_used IS NOT NULL GROUP BY model_used ORDER BY cnt DESC");
$peak_day=count($daily)?max(array_column($daily,'cnt')):0;
$rag_count=array_sum(array_column(array_filter($resp_types,fn($r)=>stripos($r['rt'],'rag')!==false),'cnt'));
$faq_count=array_sum(array_column(array_filter($resp_types,fn($r)=>stripos($r['rt'],'faq')!==false),'cnt'));
$qs_change=$prev_queries?round((($total_queries-$prev_queries)/$prev_queries)*100,1):0;
$ss_change=$prev_sessions?round((($total_sessions-$prev_sessions)/$prev_sessions)*100,1):0;
$avg_per_session=$total_sessions?round($total_queries/$total_sessions,1):0;

// ── CONVERSATIONS ──
$conv_total=fd($conn,"SELECT COUNT(*) v FROM conversations c JOIN web_sessions w ON w.session_id=c.session_id WHERE w.$dsql_time")[0]['v']??0;
$conv_completed=fd($conn,"SELECT COUNT(*) v FROM conversations c JOIN web_sessions w ON c.session_id=w.session_id WHERE c.status='completed' AND w.$dsql_time")[0]['v']??0;
$avg_sess_dur=fd($conn,"SELECT AVG(duration_seconds) v FROM web_sessions WHERE $dsql_time")[0]['v']??0;
$device_breakdown=fd($conn,"SELECT COALESCE(device_type,'unknown') dt,COUNT(*) cnt FROM web_sessions WHERE $dsql_time GROUP BY device_type ORDER BY cnt DESC");

// ── FEEDBACK ──
$fb_all=fd($conn,"SELECT rating FROM feedback WHERE $dsql");
$fb_total=count($fb_all);
$fb_excellent=count(array_filter($fb_all,fn($f)=>$f['rating']==='excellent'));
$fb_good=count(array_filter($fb_all,fn($f)=>$f['rating']==='good'));
$fb_bad=count(array_filter($fb_all,fn($f)=>$f['rating']==='bad'));
$fb_rate=$fb_total?round(($fb_excellent/$fb_total)*100,1):0;
$prev_fb=fd($conn,"SELECT rating FROM feedback WHERE $prev_dsql");
$prev_fb_exc=count(array_filter($prev_fb,fn($f)=>$f['rating']==='excellent'));
$prev_fb_rate=count($prev_fb)?round(($prev_fb_exc/count($prev_fb))*100,1):0;
$fb_change=round($fb_rate-$prev_fb_rate,1);
$fb_by_cat=fd($conn,"SELECT category,COUNT(*) cnt FROM feedback WHERE $dsql GROUP BY category ORDER BY cnt DESC");
$fb_reviewed=fd($conn,"SELECT COUNT(*) v FROM feedback WHERE is_reviewed=1 AND $dsql")[0]['v']??0;

// ── REACTIONS ──
$reactions=fd($conn,"SELECT reaction_type,COUNT(*) cnt FROM message_reactions WHERE $dsql GROUP BY reaction_type ORDER BY cnt DESC");

// ── USER QUERIES / INQUIRIES ──
$uq_all=fd($conn,"SELECT status,priority,query_type FROM user_queries WHERE $dsql_sub");
$uq_total=count($uq_all);
$uq_pending=count(array_filter($uq_all,fn($q)=>$q['status']==='pending'));
$uq_resolved=count(array_filter($uq_all,fn($q)=>$q['status']==='resolved'));
$uq_inprog=count(array_filter($uq_all,fn($q)=>$q['status']==='in_progress'));
$uq_urgent=count(array_filter($uq_all,fn($q)=>$q['priority']==='urgent'));
$uq_high=count(array_filter($uq_all,fn($q)=>$q['priority']==='high'));
$uq_by_type=fd($conn,"SELECT query_type,COUNT(*) cnt FROM user_queries WHERE $dsql_sub GROUP BY query_type ORDER BY cnt DESC");
$uq_avg_res=fd($conn,"SELECT AVG(TIMESTAMPDIFF(HOUR,submitted_at,resolved_at)) v FROM user_queries WHERE status='resolved' AND resolved_at IS NOT NULL AND $dsql_sub")[0]['v']??0;

// ── FAQ ──
$faq_top=fd($conn,"SELECT user_message q,COUNT(*) cnt FROM chat_messages WHERE $dsql AND user_message IS NOT NULL AND user_message!='' GROUP BY user_message ORDER BY cnt DESC LIMIT 10");

// ── KNOWLEDGE BASE ──
$kb_entities=fd($conn,"SELECT COUNT(*) v FROM university_entities WHERE is_active=1")[0]['v']??0;
$kb_chunks=fd($conn,"SELECT COUNT(*) v FROM entity_knowledge_chunks WHERE is_active=1")[0]['v']??0;
$kb_entity_types=fd($conn,"SELECT et.type_label,COUNT(ue.entity_id) cnt FROM entity_types et LEFT JOIN university_entities ue ON ue.entity_type_id=et.type_id AND ue.is_active=1 WHERE et.is_active=1 GROUP BY et.type_id,et.type_label ORDER BY cnt DESC");
$kb_recent_changes=fd($conn,"SELECT eh.action,COUNT(*) cnt FROM entity_history eh WHERE eh.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY eh.action");
$kb_items=fd($conn,"SELECT COUNT(*) v FROM campus_knowledge_items WHERE is_active=1")[0]['v']??0;
$kb_items_by_cat=fd($conn,"SELECT category,COUNT(*) cnt FROM campus_knowledge_items WHERE is_active=1 GROUP BY category ORDER BY cnt DESC LIMIT 8");

// ── AI MODELS ──
$ai_models=fd($conn,"SELECT model_name,model_type,model_version,status,usage_count,is_default FROM ai_models ORDER BY is_default DESC,status ASC");
$ai_active=count(array_filter($ai_models,fn($m)=>$m['status']==='active'));
$ai_perf=fd($conn,"SELECT m.model_name,AVG(p.avg_response_time_ms) avg_rt,SUM(p.total_requests) reqs,AVG(p.avg_similarity_score) avg_sim,SUM(p.successful_responses) succ,SUM(p.failed_responses) fail FROM model_performance_metrics p JOIN ai_models m ON m.model_id=p.model_id WHERE p.metric_date>=DATE_SUB(NOW(),INTERVAL $days DAY) GROUP BY p.model_id,m.model_name");

// ── SCRAPING ──
$scrape_sources=fd($conn,"SELECT source_name,is_active,success_count,failure_count,last_scraped FROM scraping_sources ORDER BY is_active DESC,last_scraped DESC");
$scrape_total=fd($conn,"SELECT COUNT(*) v FROM scraped_content")[0]['v']??0;
$scrape_by_status=fd($conn,"SELECT status,COUNT(*) cnt FROM scraped_content GROUP BY status ORDER BY cnt DESC");
$scrape_indexed=fd($conn,"SELECT COUNT(*) v FROM scraped_content WHERE status='indexed'")[0]['v']??0;
$scrape_new=fd($conn,"SELECT COUNT(*) v FROM scraped_content WHERE scraped_at>=DATE_SUB(NOW(),INTERVAL $days DAY)")[0]['v']??0;

// ── SYSTEM HEALTH ──
$sys_errors=fd($conn,"SELECT log_level,COUNT(*) cnt FROM system_logs WHERE timestamp>=DATE_SUB(NOW(),INTERVAL $days DAY) GROUP BY log_level ORDER BY FIELD(log_level,'critical','error','warning','info','debug')");
$err_unresolved=fd($conn,"SELECT COUNT(*) v FROM error_logs WHERE is_resolved=0")[0]['v']??0;
$err_total=fd($conn,"SELECT COUNT(*) v FROM error_logs WHERE $dsql")[0]['v']??0;
$admin_actions=fd($conn,"SELECT module,COUNT(*) cnt FROM admin_activity_logs WHERE timestamp>=DATE_SUB(NOW(),INTERVAL $days DAY) GROUP BY module ORDER BY cnt DESC LIMIT 6");

// ── DATE LABEL ──
switch($dr){case'7':$dl='Last 7 Days';break;case'30':$dl='Last 30 Days';break;case'60':$dl='Last 60 Days';break;case'custom':$dl="Custom: $from → $to";break;default:$dl='Last 30 Days';}

// ── PDF EXPORT ──
if(($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['export']??'')==='pdf')||(($_GET['export']??'')==='pdf')){
    if(!file_exists('tcpdf/tcpdf.php')) die("TCPDF not found.");
    require_once 'tcpdf/tcpdf.php';

    $def_sects = ['overview','kb','ai','scraping','inquiries','feedback','intents','faq','graphs','health'];
    $sel_sects = isset($_REQUEST['sections']) && is_array($_REQUEST['sections']) && !empty($_REQUEST['sections'])
        ? $_REQUEST['sections']
        : $def_sects;

    $pdf=new TCPDF('P','mm','A4',true,'UTF-8',false);
    $pdf->SetCreator('MMU Chatbot Admin');
    $pdf->SetAuthor($admin['username']??'Admin');
    $pdf->SetTitle('MMU Chatbot System – Report');
    $pdf->SetMargins(15,20,15);
    $pdf->SetAutoPageBreak(true,15);
    $pdf->SetFont('helvetica','',11);
    $pdf->AddPage();
    $pdf->Image('images/MMU-Logo-long-bgwhite.png','','',160,0,'PNG','','T',true,300,'C');
    $pdf->Ln(45);
    $pdf->SetFont('helvetica','B',20);$pdf->SetTextColor(23,70,162);
    $pdf->Cell(0,12,'Mountains of the Moon University',0,1,'C');
    $pdf->SetFont('helvetica','B',15);
    $pdf->Cell(0,9,'Campus Query Chatbot System – Report',0,1,'C');
    $pdf->SetFont('helvetica','',11);$pdf->SetTextColor(34,34,34);
    $pdf->Cell(0,7,'Period: '.$dl,0,1,'C');
    $pdf->Cell(0,7,'Generated by: '.($admin['username']??'Admin').' | '.date('F j, Y \a\t H:i'),0,1,'C');
    $pdf->Ln(6);
    $css='<style>table{width:100%;border-collapse:collapse;margin-bottom:14px;}th,td{padding:7px 9px;border:1px solid #dde1ea;font-size:10pt;}th{background:#eef2fb;color:#1746a2;font-weight:bold;}h2{color:#1746a2;font-size:13pt;margin:18px 0 8px;border-bottom:1px solid #c4cedf;padding-bottom:4px;}h3{color:#374151;font-size:11pt;margin:12px 0 6px;}</style>';
    $h=$css;

    if (in_array('overview', $sel_sects)) {
        $h.='<h2>1. Chatbot Usage Summary</h2><table><tr><th>Metric</th><th>Value</th><th>vs Prev Period</th></tr>'
           .'<tr><td>Total Messages</td><td>'.$total_queries.'</td><td>'.($qs_change>=0?'+':'').$qs_change.'%</td></tr>'
           .'<tr><td>Unique Sessions</td><td>'.$total_sessions.'</td><td>'.($ss_change>=0?'+':'').$ss_change.'%</td></tr>'
           .'<tr><td>Avg Queries/Session</td><td>'.$avg_per_session.'</td><td>–</td></tr>'
           .'<tr><td>Avg Response Time</td><td>'.round($avg_resp).' ms</td><td>–</td></tr>'
           .'<tr><td>Avg Confidence Score</td><td>'.round($avg_conf*100,1).'%</td><td>–</td></tr>'
           .'<tr><td>Context Retrieved Responses</td><td>'.$ctx_used.'</td><td>–</td></tr>'
           .'<tr><td>Peak Day Queries</td><td>'.$peak_day.'</td><td>–</td></tr>'
           .'<tr><td>Total Conversations</td><td>'.$conv_total.'</td><td>–</td></tr>'
           .'<tr><td>Completed Conversations</td><td>'.$conv_completed.'</td><td>–</td></tr>'
           .'<tr><td>Avg Session Duration</td><td>'.round($avg_sess_dur).' s</td><td>–</td></tr>'
           .'</table>';

        if (!empty($device_breakdown)) {
            $h .= '<h3>Session Device Breakdown</h3><table><tr><th>Device Type</th><th>Sessions</th><th>Share</th></tr>';
            $tot_dev = array_sum(array_column($device_breakdown, 'cnt'));
            foreach ($device_breakdown as $d) {
                $share = $tot_dev ? round($d['cnt'] / $tot_dev * 100, 1) : 0;
                $h .= '<tr><td>' . htmlspecialchars(ucfirst($d['dt'])) . '</td><td>' . number_format($d['cnt']) . '</td><td>' . $share . '%</td></tr>';
            }
            $h .= '</table>';
        }

        if (!empty($resp_types)) {
            $h .= '<h3>Response Type Breakdown</h3><table><tr><th>Response Type</th><th>Count</th><th>Share</th></tr>';
            $rt_total = array_sum(array_column($resp_types, 'cnt'));
            foreach ($resp_types as $rt) {
                $pct = $rt_total ? round($rt['cnt'] / $rt_total * 100, 1) : 0;
                $h .= '<tr><td>' . htmlspecialchars(ucwords(str_replace('_', ' ', $rt['rt']))) . '</td><td>' . number_format($rt['cnt']) . '</td><td>' . $pct . '%</td></tr>';
            }
            $h .= '</table>';
        }

        if (!empty($models_used)) {
            $h .= '<h3>Model Usage in Period</h3><table><tr><th>Model</th><th>Messages Handled</th></tr>';
            foreach ($models_used as $m) {
                $h .= '<tr><td>' . htmlspecialchars($m['m']) . '</td><td>' . number_format($m['cnt']) . '</td></tr>';
            }
            $h .= '</table>';
        }
    }

    if (in_array('kb', $sel_sects)) {
        $h.='<h2>2. Knowledge Base Health</h2><table><tr><th>Metric</th><th>Value</th></tr>'
           .'<tr><td>Active Entities</td><td>'.$kb_entities.'</td></tr>'
           .'<tr><td>Knowledge Chunks</td><td>'.$kb_chunks.'</td></tr>'
           .'<tr><td>Campus Knowledge Items</td><td>'.$kb_items.'</td></tr>'
           .'</table>';
        if ($kb_entity_types) {
            $h .= '<h3>Entity Type Breakdown</h3><table><tr><th>Type</th><th>Count</th></tr>';
            foreach ($kb_entity_types as $et) {
                $h .= '<tr><td>' . htmlspecialchars($et['type_label']) . '</td><td>' . $et['cnt'] . '</td></tr>';
            }
            $h .= '</table>';
        }
        if (!empty($kb_items_by_cat)) {
            $h .= '<h3>Knowledge Items by Category</h3><table><tr><th>Category</th><th>Items</th></tr>';
            foreach ($kb_items_by_cat as $c) {
                $h .= '<tr><td>' . htmlspecialchars(ucfirst($c['category'])) . '</td><td>' . number_format($c['cnt']) . '</td></tr>';
            }
            $h .= '</table>';
        }
        if (!empty($kb_recent_changes)) {
            $h .= '<h3>Entity Changes (Last 30 Days)</h3><table><tr><th>Action</th><th>Count</th></tr>';
            foreach ($kb_recent_changes as $ch) {
                $h .= '<tr><td>' . htmlspecialchars(ucfirst($ch['action'])) . '</td><td>' . number_format($ch['cnt']) . '</td></tr>';
            }
            $h .= '</table>';
        }
    }

    if (in_array('ai', $sel_sects)) {
        $h.='<h2>3. AI Models</h2><table><tr><th>Model</th><th>Type</th><th>Status</th><th>Usage Count</th></tr>';
        foreach($ai_models as $m) {
            $h.='<tr><td>'.htmlspecialchars($m['model_name']).'</td><td>'.htmlspecialchars($m['model_type']).'</td><td>'.htmlspecialchars($m['status']).($m['is_default']?' (Default)':'').'</td><td>'.number_format($m['usage_count']).'</td></tr>';
        }
        $h.='</table>';

        if (!empty($ai_perf)) {
            $h .= '<h3>Model Performance Metrics</h3><table><tr><th>Model</th><th>Requests</th><th>Avg Resp (ms)</th><th>Avg Similarity</th><th>Success</th><th>Failed</th></tr>';
            foreach ($ai_perf as $p) {
                $h .= '<tr><td>' . htmlspecialchars($p['model_name']) . '</td><td>' . number_format($p['reqs']) . '</td><td>' . round($p['avg_rt']) . ' ms</td><td>' . round($p['avg_sim'] * 100, 1) . '%</td><td>' . number_format($p['succ']) . '</td><td>' . number_format($p['fail']) . '</td></tr>';
            }
            $h .= '</table>';
        }
    }

    if (in_array('scraping', $sel_sects)) {
        $h.='<h2>4. Web Scraping Overview</h2><table><tr><th>Metric</th><th>Value</th></tr>'
           .'<tr><td>Total Scraped Pages</td><td>'.$scrape_total.'</td></tr>'
           .'<tr><td>Indexed Pages</td><td>'.$scrape_indexed.'</td></tr>'
           .'<tr><td>New (Period)</td><td>'.$scrape_new.'</td></tr>'
           .'</table>';

        if (!empty($scrape_by_status)) {
            $h .= '<h3>Scrape Status Breakdown</h3><table><tr><th>Status</th><th>Count</th><th>Share</th></tr>';
            $st_total = array_sum(array_column($scrape_by_status, 'cnt'));
            foreach ($scrape_by_status as $sb) {
                $pct = $st_total ? round($sb['cnt'] / $st_total * 100, 1) : 0;
                $h .= '<tr><td>' . htmlspecialchars(ucfirst($sb['status'])) . '</td><td>' . number_format($sb['cnt']) . '</td><td>' . $pct . '%</td></tr>';
            }
            $h .= '</table>';
        }

        if (!empty($scrape_sources)) {
            $h .= '<h3>Scraping Sources</h3><table><tr><th>Source</th><th>Status</th><th>Success</th><th>Fail</th></tr>';
            foreach ($scrape_sources as $src) {
                $h .= '<tr><td>' . htmlspecialchars(mb_strimwidth($src['source_name'], 0, 45, '…')) . '</td><td>' . ($src['is_active'] ? 'Active' : 'Inactive') . '</td><td>' . number_format($src['success_count']) . '</td><td>' . number_format($src['failure_count']) . '</td></tr>';
            }
            $h .= '</table>';
        }
    }

    if (in_array('inquiries', $sel_sects)) {
        $h.='<h2>5. User Inquiries</h2><table><tr><th>Status</th><th>Count</th></tr>'
           .'<tr><td>Pending</td><td>'.$uq_pending.'</td></tr>'
           .'<tr><td>In Progress</td><td>'.$uq_inprog.'</td></tr>'
           .'<tr><td>Resolved</td><td>'.$uq_resolved.'</td></tr>'
           .'<tr><td>Urgent Priority</td><td>'.$uq_urgent.'</td></tr>'
           .'<tr><td>Avg Resolution Time</td><td>'.round($uq_avg_res,1).' hrs</td></tr>'
           .'</table>';

        if (!empty($uq_by_type)) {
            $h .= '<h3>Inquiries by Type</h3><table><tr><th>Query Type</th><th>Count</th><th>Share</th></tr>';
            foreach ($uq_by_type as $t) {
                $pct = $uq_total ? round($t['cnt'] / $uq_total * 100, 1) : 0;
                $h .= '<tr><td>' . htmlspecialchars(ucwords(str_replace('_', ' ', $t['query_type']))) . '</td><td>' . number_format($t['cnt']) . '</td><td>' . $pct . '%</td></tr>';
            }
            $h .= '</table>';
        }
    }

    if (in_array('feedback', $sel_sects)) {
        $h.='<h2>6. Feedback & Satisfaction</h2><table><tr><th>Rating</th><th>Count</th><th>%</th></tr>'
           .'<tr><td>Excellent</td><td>'.$fb_excellent.'</td><td>'.($fb_total?round($fb_excellent/$fb_total*100,1):0).'%</td></tr>'
           .'<tr><td>Good</td><td>'.$fb_good.'</td><td>'.($fb_total?round($fb_good/$fb_total*100,1):0).'%</td></tr>'
           .'<tr><td>Bad</td><td>'.$fb_bad.'</td><td>'.($fb_total?round($fb_bad/$fb_total*100,1):0).'%</td></tr>'
           .'<tr><td><b>Total</b></td><td><b>'.$fb_total.'</b></td><td>–</td></tr>'
           .'</table>';

        if (!empty($fb_by_cat)) {
            $h .= '<h3>Feedback by Category</h3><table><tr><th>Category</th><th>Count</th></tr>';
            foreach ($fb_by_cat as $c) {
                $h .= '<tr><td>' . htmlspecialchars(ucfirst($c['category'])) . '</td><td>' . number_format($c['cnt']) . '</td></tr>';
            }
            $h .= '</table>';
        }

        if (!empty($reactions)) {
            $h .= '<h3>Message Reactions</h3><table><tr><th>Reaction</th><th>Count</th></tr>';
            foreach ($reactions as $rx) {
                $h .= '<tr><td>' . htmlspecialchars(ucwords(str_replace('_', ' ', $rx['reaction_type']))) . '</td><td>' . number_format($rx['cnt']) . '</td></tr>';
            }
            $h .= '</table>';
        }
    }

    if (in_array('intents', $sel_sects)) {
        $h.='<h2>7. Top Intent Classifications</h2><table><tr><th>Intent</th><th>Count</th></tr>';
        foreach($intents as $i) {
            $h.='<tr><td>'.htmlspecialchars(ucwords(str_replace('_',' ',$i['nm']))).'</td><td>'.$i['cnt'].'</td></tr>';
        }
        $h.='</table>';
    }

    if (in_array('faq', $sel_sects)) {
        $h.='<h2>8. Top 10 User Questions</h2><table><tr><th>#</th><th>Question</th><th>Times Asked</th></tr>';
        foreach($faq_top as $k=>$f) {
            $h.='<tr><td>'.($k+1).'</td><td>'.htmlspecialchars(mb_strimwidth($f['q']??'',0,90,'…')).'</td><td>'.$f['cnt'].'</td></tr>';
        }
        $h.='</table>';
    }

    if (in_array('health', $sel_sects)) {
        $h.='<h2>9. System Health</h2><table><tr><th>Log Level</th><th>Count</th></tr>';
        foreach($sys_errors as $se) {
            $h.='<tr><td>'.ucfirst($se['log_level']).'</td><td>'.$se['cnt'].'</td></tr>';
        }
        $h.='<tr><td>Unresolved Application Errors</td><td>'.$err_unresolved.'</td></tr></table>';

        if (!empty($admin_actions)) {
            $h .= '<h3>Admin Activity by Module</h3><table><tr><th>Module</th><th>Actions</th></tr>';
            foreach ($admin_actions as $aa) {
                $h .= '<tr><td>' . htmlspecialchars(ucwords(str_replace('_', ' ', $aa['module'] ?? '–'))) . '</td><td>' . number_format($aa['cnt']) . '</td></tr>';
            }
            $h .= '</table>';
        }
    }

    $h.='<div style="page-break-before:always;margin-top:60px;">'
       .'<h2>Approval</h2>'
       .'<p><b>Approved by:</b></p>'
       .'<p>Name: <span style="display:inline-block;min-width:220px;border-bottom:1px solid #222;"> </span></p>'
       .'<p>Designation: <span style="display:inline-block;min-width:220px;border-bottom:1px solid #222;"> </span></p>'
       .'<p>Signature: <span style="display:inline-block;min-width:220px;border-bottom:1px solid #222;"> </span></p>'
       .'<p>Date: <span style="display:inline-block;min-width:180px;border-bottom:1px solid #222;"> </span></p>'
       .'</div>';
    $pdf->writeHTML($h,true,false,true,false,'');
    if(ob_get_length())ob_clean();
    $pdf->Output('MMU_Chatbot_Enterprise_Report_'.date('Ymd').'.pdf','D');
    exit;
}
?>
<?php include __DIR__ . '/report_html.php'; ?>