<?php

namespace Kino\Api;

use PDO;

class SearchController extends BaseController
{
    public function search($post)
    {
        $codes = array_filter(array_map('trim', explode("\n", $post['codes'] ?? $_GET['codes'] ?? '')));

        if (empty($codes)) {
            $this->jsonExit(['error' => 'No se proporcionaron códigos']);
        }

        // Global helper
        $result = greedy_search($this->db, $codes);
        $this->jsonExit($result);
    }

    public function searchByCode($request)
    {
        $code = trim($request['code'] ?? '');
        $result = search_by_code($this->db, $code);
        $this->jsonExit(['documents' => $result]);
    }

    public function suggest($get)
    {
        $term = trim($get['term'] ?? '');
        $suggestions = suggest_codes($this->db, $term, 10);
        $this->jsonExit($suggestions);
    }

    public function stats()
    {
        $stats = get_search_stats($this->db);
        $this->jsonExit($stats);
    }

    public function fulltextSearch($request)
    {
        $query = trim($request['query'] ?? '');
        $limit = min(100, max(1, (int) ($request['limit'] ?? 50)));

        if (strlen($query) < 3) {
            $this->jsonExit(['error' => 'El término debe tener al menos 3 caracteres']);
        }

        $stmt = $this->db->prepare("
            SELECT 
                d.id, d.tipo, d.numero, d.fecha, d.proveedor, d.ruta_archivo,
                d.datos_extraidos
            FROM documentos d
            WHERE d.datos_extraidos LIKE ? OR d.numero LIKE ?
            ORDER BY d.fecha DESC, d.id DESC
            LIMIT ?
        ");
        $likeQuery = '%' . $query . '%';
        $stmt->execute([$likeQuery, $likeQuery, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $r) {
            $data = json_decode($r['datos_extraidos'], true);
            $text = $data['text'] ?? '';
            $snippet = '';

            if (!empty($text)) {
                $pos = stripos($text, $query);
                if ($pos !== false) {
                    $start = max(0, $pos - 60);
                    $end = min(strlen($text), $pos + strlen($query) + 60);
                    $snippet = ($start > 0 ? '...' : '') .
                        substr($text, $start, $end - $start) .
                        ($end < strlen($text) ? '...' : '');
                    $snippet = preg_replace('/\s+/', ' ', trim($snippet));
                }
            }

            $occurrences = substr_count(strtolower($text), strtolower($query));

            $results[] = [
                'id' => (int) $r['id'],
                'tipo' => $r['tipo'],
                'numero' => $r['numero'],
                'fecha' => $r['fecha'],
                'proveedor' => $r['proveedor'],
                'ruta_archivo' => $r['ruta_archivo'],
                'snippet' => $snippet,
                'occurrences' => $occurrences
            ];
        }

        usort($results, fn($a, $b) => $b['occurrences'] - $a['occurrences']);

        $this->jsonExit([
            'query' => $query,
            'count' => count($results),
            'results' => $results
        ]);
    }
}
