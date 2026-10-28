import utils.http_session
import utils.transport
import utils.session
def request(flow):
	flow.request.headers["x-requests-proxy"] = "http"

def response(flow):
	flow.response.headers[b"x-requests-proxied"] = "http"
