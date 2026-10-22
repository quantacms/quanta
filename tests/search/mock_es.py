from http.server import BaseHTTPRequestHandler, HTTPServer
import json

class Handler(BaseHTTPRequestHandler):
    def reply(self, code, payload=None):
        self.send_response(code)
        self.send_header('Content-Type', 'application/vnd.elasticsearch+json;compatible-with=9')
        self.send_header('X-Elastic-Product', 'Elasticsearch')
        self.end_headers()
        if payload is not None:
            self.wfile.write(json.dumps(payload).encode())

    def do_HEAD(self):
        self.reply(200)

    def do_PUT(self):
        self.reply(200, {'acknowledged': True, 'result': 'created'})

    def do_POST(self):
        length = int(self.headers.get('Content-Length', '0'))
        body = json.loads(self.rfile.read(length) or b'{}')
        if self.path.endswith('/_search') and 'aggs' in body:
            self.reply(200, {'took': 1, 'timed_out': False, '_shards': {'total': 1, 'successful': 1, 'skipped': 0, 'failed': 0}, 'hits': {'total': {'value': 0, 'relation': 'eq'}, 'max_score': None, 'hits': []}, 'aggregations': {'facet': {'doc_count_error_upper_bound': 0, 'sum_other_doc_count': 0, 'buckets': [{'key': 'news', 'doc_count': 3}]}}})
        elif self.path.endswith('/_search'):
            self.reply(200, {'took': 1, 'timed_out': False, '_shards': {'total': 1, 'successful': 1, 'skipped': 0, 'failed': 0}, 'hits': {'total': {'value': 1, 'relation': 'eq'}, 'max_score': 1.0, 'hits': [{'_index': 'x', '_id': 'home', '_score': 1.0, '_source': {'name': 'home', 'title': 'Home', 'teaser': 'Hello'}}]}})
        else:
            self.reply(200, {'result': 'created', '_shards': {'total': 1, 'successful': 1, 'failed': 0}})

    def log_message(self, *_):
        pass

HTTPServer(('127.0.0.1', 19200), Handler).serve_forever()
