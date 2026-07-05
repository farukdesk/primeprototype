package bd.ac.primeuniversity.studentportal.ui.notices

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.Notice
import bd.ac.primeuniversity.studentportal.databinding.FragmentNoticesBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/** Notices tab with a University / Department segmented control and paging. */
class NoticesFragment : Fragment() {

    private var _binding: FragmentNoticesBinding? = null
    private val binding get() = _binding!!
    private val app: PrimeApp by lazy { requireActivity().application as PrimeApp }

    private val adapter = NoticeAdapter(showPreview = true) {
        startActivity(NoticeDetailActivity.intent(requireContext(), it.id, it.type))
    }

    // Per-segment paging state
    private val items = mutableListOf<Notice>()
    private var segment = SEG_UNIVERSITY
    private var page = 1
    private var total = 0
    private var loading = false

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?
    ): View {
        _binding = FragmentNoticesBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        val lm = LinearLayoutManager(requireContext())
        binding.list.layoutManager = lm
        binding.list.adapter = adapter
        binding.list.layoutAnimation = android.view.animation.AnimationUtils
            .loadLayoutAnimation(requireContext(), R.anim.layout_animation_fall_down)
        binding.list.addOnScrollListener(object : RecyclerView.OnScrollListener() {
            override fun onScrolled(rv: RecyclerView, dx: Int, dy: Int) {
                if (dy <= 0) return
                if (lm.findLastVisibleItemPosition() >= adapter.itemCount - 3) loadMore()
            }
        })

        binding.swipeRefresh.setColorSchemeResources(
            R.color.primary, R.color.accent, R.color.cat_campus
        )
        binding.segUniversity.setOnClickListener { selectSegment(SEG_UNIVERSITY) }
        binding.segDepartment.setOnClickListener { selectSegment(SEG_DEPARTMENT) }
        binding.swipeRefresh.setOnRefreshListener { reload(fromSwipe = true) }

        reload()
    }

    private fun selectSegment(which: Int) {
        if (segment == which && items.isNotEmpty()) return
        segment = which
        val selected = R.drawable.bg_segment_selected
        binding.segUniversity.setBackgroundResource(
            if (which == SEG_UNIVERSITY) selected else android.R.color.transparent
        )
        binding.segDepartment.setBackgroundResource(
            if (which == SEG_DEPARTMENT) selected else android.R.color.transparent
        )
        binding.segUniversity.setTextColor(segColor(which == SEG_UNIVERSITY))
        binding.segDepartment.setTextColor(segColor(which == SEG_DEPARTMENT))
        reload()
    }

    private fun segColor(selected: Boolean): Int =
        if (selected) requireContext().getColor(R.color.primary) else 0xB3FFFFFF.toInt()

    private val typeName get() = if (segment == SEG_UNIVERSITY) "university" else "department"

    private fun reload(fromSwipe: Boolean = false) {
        page = 1
        total = 0
        items.clear()
        adapter.submitList(emptyList())
        binding.emptyState.visibility = View.GONE
        if (!fromSwipe) binding.progress.visibility = View.VISIBLE
        fetch()
    }

    private fun loadMore() {
        if (loading) return
        if (items.size >= total && total != 0) return
        page += 1
        fetch()
    }

    private fun fetch() {
        loading = true
        lifecycleScope.launch {
            val result = app.repository.getNotices(typeName, page)
            loading = false
            binding.progress.visibility = View.GONE
            binding.swipeRefresh.isRefreshing = false
            when (result) {
                is AppResult.Success -> {
                    total = result.data.total
                    val firstPage = page == 1
                    items.addAll(result.data.notices)
                    adapter.submitList(items.toList())
                    if (firstPage && items.isNotEmpty()) {
                        binding.list.scheduleLayoutAnimation()
                    }
                    binding.emptyState.visibility =
                        if (items.isEmpty()) View.VISIBLE else View.GONE
                }
                is AppResult.Error -> {
                    if (items.isEmpty()) binding.emptyState.visibility = View.VISIBLE
                }
            }
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    companion object {
        private const val SEG_UNIVERSITY = 0
        private const val SEG_DEPARTMENT = 1
    }
}
