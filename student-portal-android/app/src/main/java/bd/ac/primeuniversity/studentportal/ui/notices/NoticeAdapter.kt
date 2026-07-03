package bd.ac.primeuniversity.studentportal.ui.notices

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.Notice
import bd.ac.primeuniversity.studentportal.databinding.ItemNoticeBinding

/**
 * Shared adapter for university/department notice lists (dashboard + notices tab).
 * When [showPreview] is false the content preview line is hidden (dashboard).
 */
class NoticeAdapter(
    private val showPreview: Boolean = true,
    private val onClick: (Notice) -> Unit,
) : ListAdapter<Notice, NoticeAdapter.VH>(DIFF) {

    inner class VH(val binding: ItemNoticeBinding) : RecyclerView.ViewHolder(binding.root)

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemNoticeBinding.inflate(
            LayoutInflater.from(parent.context), parent, false
        )
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) {
        val notice = getItem(position)
        val ctx = holder.itemView.context
        val accent = ContextCompat.getColor(
            ctx, if (notice.isDepartment) R.color.success else R.color.primary
        )

        with(holder.binding) {
            accentStrip.setBackgroundColor(accent)
            typeTag.text = if (notice.isDepartment)
                (notice.deptName ?: ctx.getString(R.string.seg_department))
            else
                ctx.getString(R.string.label_university)
            typeTag.setTextColor(accent)
            date.text = notice.date
            attachIcon.visibility = if (notice.hasAttachment) View.VISIBLE else View.GONE
            title.text = notice.title

            if (showPreview && notice.plainContent.isNotEmpty()) {
                preview.visibility = View.VISIBLE
                preview.text = notice.plainContent
            } else {
                preview.visibility = View.GONE
            }

            root.setOnClickListener { onClick(notice) }
        }
    }

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<Notice>() {
            override fun areItemsTheSame(a: Notice, b: Notice) = a.id == b.id && a.type == b.type
            override fun areContentsTheSame(a: Notice, b: Notice) = a == b
        }
    }
}
